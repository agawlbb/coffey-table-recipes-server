<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Cache-Control: no-store');
    header('X-Accel-Expires: 0');
    header('Vary: X-Recipe-PHP-Check');
}
$token = $cli ? ($argv[1] ?? '') : ($_SERVER['HTTP_X_RECIPE_PHP_CHECK'] ?? '');
if (!hash_equals('__TOKEN_HASH__', hash('sha256', $token))) { http_response_code(404); exit; }
$root = '__APP_ROOT__';
$receipt = $root.'/storage/__RECEIPT__';
if ($cli && is_file($receipt)) exit;
try {
    foreach (['pdo_mysql','json','curl','openssl','mbstring','dom'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException('extension');
    }
    require $root.'/src/bootstrap.php';
    require_once $root.'/src/RecipeShare.php';
    $config = configuration();
    if ($config['database']['name'] !== '__DATABASE__') throw new RuntimeException('database target');
    $pdo = database($config);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        if ((int)$pdo->query('SELECT 1')->fetchColumn() !== 1) throw new RuntimeException('database');
        $id = $pdo->query('SELECT id FROM recipes WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
        $photo = null;
        if ($id !== false) {
            $recipe = RecipeShare::load($pdo, (string)$id);
            $member = $pdo->prepare('SELECT user_id FROM household_memberships WHERE household_id=? ORDER BY user_id LIMIT 1');
            $member->execute([$recipe['household_id']]);
            $userId = $member->fetchColumn();
            if ($userId === false) throw new RuntimeException('membership');
            $households = new HouseholdService($pdo);
            if (!$households->households((string)$userId)) throw new RuntimeException('households');
            (new RecipeService($pdo, $households))->detail((string)$userId, $recipe['household_id'], (string)$id);
            foreach ($recipe['photos'] as $entry) {
                if (preg_match('#^media/recipes/([a-f0-9]{48}\.(?:jpg|png|webp))$#', (string)($entry['storage_key'] ?? ''), $match)) {
                    $photo = $match[1]; break;
                }
            }
        }
        $pdo->rollBack();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if (!str_contains(RecipeImportService::websiteText('<html><body><main>PHP upgrade recipe check</main></body></html>'), 'PHP upgrade recipe check')) throw new RuntimeException('import');
    $result = ['status'=>'ok','php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,
        'extensions'=>true,'composer_bootstrap'=>true,'database_read'=>true,
        'recipe_read'=>$id !== false,'share_id'=>$id === false ? null : (string)$id,
        'photo_filename'=>$photo,'import_preprocessing'=>true];
} catch (Throwable $error) {
    $result = ['status'=>'error','php_version'=>PHP_VERSION];
}
if ($cli) {
    file_put_contents($receipt, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($receipt, 0600);
} else {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
