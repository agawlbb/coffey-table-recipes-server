<?php
declare(strict_types=1);

function migrationChecksumMatches(string $version, string $applied, string $current): bool
{
    if (hash_equals($applied, $current)) return true;
    // Publication removed an installation-specific email grant from migration 008.
    // Recognize only these exact original/cleaned files, including Windows line endings.
    return $version === '008_administrators.sql'
        && in_array($applied, [
            'b4da1f391d995deee2a2fb52574c4bd434d6251696c60e1eb7581ba2bbbbb8ba',
            '8b3672624da115f55dbe04278b007e61e976c1577b2157d9814eccad4371f1f0',
        ], true)
        && in_array($current, [
            'da221eb39d52a799649f020ada77a750b02b5636dd91059808f6ec78219fb5d5',
            '875dac9d8cb58cb7c390bd548b37140c067120e733a1f87eb15029d4d57b90b9',
        ], true);
}
