<?php
declare(strict_types=1);
// One-time, CLI-only, reviewed migration. No HTTP execution or credential output.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$run = $argv[1] ?? '';
$account = $argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $run) || !preg_match('/^[a-z0-9_]+$/', $account)) exit(1);
$root = dirname(__DIR__);
$receipt = $root . '/storage/household-images-' . $run . '.json';
if (is_file($receipt)) exit;
$lock = fopen($receipt . '.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(1);
$stage = 'target';
try {
    if (is_file($receipt)) exit;
    $config = configuration();
    if ($root !== '/home/' . $account . '/coffeybean.com/CoffeyTableRecipes'
        || $config['database']['name'] !== $account . '_recipes') {
        throw new RuntimeException('Unexpected deployment target');
    }
    $stage = 'database';
    $pdo = database($config);
    if ((int) $pdo->query("SELECT GET_LOCK('coffey_table_recipes_migrations', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Migration lock unavailable');
    }
    try {
        $version = '009_household_ai_images.sql';
        $file = $root . '/database/migrations/' . $version;
        $checksum = hash_file('sha256', $file);
        $q = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
        $q->execute([$version]);
        $applied = $q->fetchColumn();
        $backup = null;
        if ($applied !== false) {
            if ($applied !== $checksum) throw new RuntimeException('Migration checksum mismatch');
        } else {
            $stage = 'backup';
            // Save a complete snapshot of this dedicated database in protected storage.
            $backup = 'before-household-images-' . $run . '.sql.dump';
            $stream = fopen($root . '/storage/' . $backup, 'x');
            if (!$stream) throw new RuntimeException('Backup unavailable');
            chmod($root . '/storage/' . $backup, 0600);
            $write = static function (string $sql) use ($stream): void {
                if (fwrite($stream, $sql) !== strlen($sql)) throw new RuntimeException('Backup write failed');
            };
            try {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
                $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
                foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Unexpected table name');
                    $definition = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
                    $write($definition . ";\n");
                    $data = $pdo->query("SELECT * FROM `$table`");
                    $data->setFetchMode(PDO::FETCH_ASSOC);
                    foreach ($data as $row) {
                        $values = array_map(static fn($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), $row);
                        $write("INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n");
                    }
                }
                $write("SET FOREIGN_KEY_CHECKS=1;\n");
                if (!fflush($stream)) throw new RuntimeException('Backup flush failed');
                $pdo->commit();
            } finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
                fclose($stream);
            }
            if (filesize($root . '/storage/' . $backup) < 1000) throw new RuntimeException('Incomplete backup');
            $stage = 'migration';
            $pdo->exec(file_get_contents($file));
            $stage = 'record';
            $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')->execute([$version, $checksum]);
            $stage = 'verify';
            if ((int) $pdo->query('SELECT COUNT(*) FROM households WHERE ai_images_enabled <> 0')->fetchColumn() !== 0) {
                throw new RuntimeException('Disabled default verification failed');
            }
        }
        $result = ['status' => 'ok', 'migration' => $version, 'backup' => $backup,
            'households' => (int) $pdo->query('SELECT COUNT(*) FROM households')->fetchColumn(),
            'enabled' => (int) $pdo->query('SELECT COUNT(*) FROM households WHERE ai_images_enabled <> 0')->fetchColumn()];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('coffey_table_recipes_migrations')");
    }
} catch (Throwable $error) {
    $result = ['status' => 'error', 'stage' => $stage, 'type' => get_class($error)];
    if ($error instanceof PDOException) {
        $result['database_error'] = (int) ($error->errorInfo[1] ?? 0);
        if ($stage === 'migration' && $result['database_error'] === 1142) {
            // Protected cPanel-only receipt; never include this identity in logs.
            $result['database_user'] = $config['database']['user'];
        }
    }
}
file_put_contents($receipt, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
chmod($receipt, 0600);
flock($lock, LOCK_UN);
fclose($lock);
