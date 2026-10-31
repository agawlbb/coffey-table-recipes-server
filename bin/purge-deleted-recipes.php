<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR,"Recipe cleanup moved to the VPS ctr-recipe-cleanup.timer. Remove this obsolete cron entry.\n");
exit(1);
