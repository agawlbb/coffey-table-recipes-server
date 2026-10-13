#!/usr/bin/env bash
set -euo pipefail
for test in invitation-mail verification migration_checksums recipe_deletion recipe_sharing recipe_photo source_photos recipe_ai website_import website_photos; do
  php -d error_reporting=32767 "tests/$test.php"
done
