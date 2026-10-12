<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/MigrationChecksums.php';

try {
    $pdo = database(configuration());
    $locked = (int) $pdo->query("SELECT GET_LOCK('coffey_table_recipes_migrations', 10)")->fetchColumn();
    if ($locked !== 1) throw new RuntimeException('Another migration process holds the lock.');
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(120) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB');
        $applied = $pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $files = glob(dirname(__DIR__) . '/database/migrations/*.sql');
        sort($files);
        foreach ($files as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            if (isset($applied[$version])) {
                if (!migrationChecksumMatches($version, $applied[$version], $checksum)) throw new RuntimeException("Applied migration has changed: $version");
                continue;
            }
            // MySQL DDL commits implicitly. Do not pretend migrations are transactional.
            foreach (explode(';', file_get_contents($file)) as $statement) {
                if (trim($statement) !== '') $pdo->exec($statement);
            }
            $insert = $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
            $insert->execute([$version, $checksum]);
            echo "Applied $version\n";
        }
        echo "Database is up to date.\n";
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('coffey_table_recipes_migrations')");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
