<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
try {
    $pdo = database(configuration());
    if ((int)$pdo->query("SELECT GET_LOCK('coffey_recipe_trash_cleanup',0)")->fetchColumn() !== 1) exit;
    try {
        $service = new RecipeDeletionService($pdo, new HouseholdService($pdo));
        $total = 0;
        do { $removed = $service->purgeExpired(dirname(__DIR__).'/media/recipes'); $total += $removed; } while ($removed === 100);
        echo "Removed $total expired recipes.\n";
    } finally { $pdo->query("SELECT RELEASE_LOCK('coffey_recipe_trash_cleanup')"); }
} catch (Throwable $error) { fwrite(STDERR, "Recipe cleanup failed; inspect protected server logs.\n"); error_log((string)$error); exit(1); }
