# Shared-host application retirement

This branch is source preparation, not a deployed migration. It starts at shared
main `cbf1e08`; do not merge until approved. Follow the VPS repository's
[cutover instructions](https://github.com/agawlbb/coffey-table-recipes-vps/blob/feature/vps-remaining-php/REMAINING-PHP.md)
before retiring the old endpoints.

`main` pushes and manual workflow dispatch run the retirement workflow. Feature
branch pushes do not deploy. `tool/deploy-backend.py` uploads and reads back only
the four application entry-point files and checks their 410 responses, static
app availability and private-path protection. Existing reusable migration helpers
remain for history; routine retirement does not call them. No package/rollback
archives, schema migrations or cron jobs are created. The old scheduling command
refuses to create another InMotion cleanup job.

`tool/move-recipe-media.py` is a separately invoked one-time operation on the VPS
host: inventory by default, `--apply --vps-source PATH` for verified transfer and
transactional managed-image reference changes, then explicit `--retire-source`
after replacement validation. It requires private cPanel configuration outside Git.
It preserves newer VPS category art, refuses photo collisions, and leaves external
images untouched. Original files remain until verified retirement. MySQL and SMTP
do not move. Static `app/` files are never uploaded or deleted by this workflow.

Run `php tests/data_routes.php` and Python unittest discovery for
`test_deploy_backend.py` and `test_move_recipe_media.py` in the development container.
These use no hosting credentials or production data.

Verified on 2026-10-31 using `dev exec`: retired HTTP routes passed; deployment
tests (3), binary media-transfer tests (6) and retired-scheduler test (1) passed.
Changed entry-point PHP lint, Python compilation and `git diff --check` passed.
No live files were uploaded, copied or removed and no cron entries were changed.
