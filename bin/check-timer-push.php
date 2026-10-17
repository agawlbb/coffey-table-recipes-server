<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
try {
    (new TimerPush(configuration()))->validateConfiguration();
    echo "Timer sender OAuth and FCM dry-run authorization verified. No notification sent.\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Timer sender check failed. Review protected credential configuration and messaging permission.\n");
    exit(1);
}
