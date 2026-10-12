<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/MigrationChecksums.php';
$original = 'b4da1f391d995deee2a2fb52574c4bd434d6251696c60e1eb7581ba2bbbbb8ba';
$cleaned = hash('sha256', str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/database/migrations/008_administrators.sql')));
$checks = [
    migrationChecksumMatches('008_administrators.sql', $original, $cleaned),
    migrationChecksumMatches('008_administrators.sql', '8b3672624da115f55dbe04278b007e61e976c1577b2157d9814eccad4371f1f0', $cleaned),
    migrationChecksumMatches('001_households.sql', $original, $original),
    !migrationChecksumMatches('001_households.sql', $original, $cleaned),
    !migrationChecksumMatches('008_administrators.sql', str_repeat('0', 64), $cleaned),
    !migrationChecksumMatches('008_administrators.sql', $original, hash('sha256', 'tampered SQL')),
];
if (in_array(false, $checks, true)) throw new RuntimeException('Migration checksum regression');
echo count($checks) . " migration checksum checks passed.\n";
