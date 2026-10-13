# Recipe deletion and recovery

All household members who can edit recipes can delete and restore their
household's recipes. Membership is checked for every operation; a foreign recipe
ID returns 404. There is no immediate permanent-delete API.

Endpoints beneath `/api/v1/households/{household}/recipes`:

- `POST /{recipe}/delete`, JSON `{"revision":"current revision"}`: returns
  `deleted_recipe_id` and an ISO UTC `purge_after` timestamp. A stale revision
  returns 409. Retrying deletion while already deleted retains the original deadline.
- `GET /deleted?offset=0`: up to 30 recoverable entries with title, ID, revision,
  deletion timestamp, deadline and `next_offset`. Expired entries are omitted.
- `POST /{recipe}/restore`, JSON `{}`: returns the normal recipe detail response.
  Restore is idempotent and returns 410 after expiry, even before physical cleanup.

Deletion sets `deleted_at`, `deleted_by_user_id`, and `purge_after`, and increments
revision. Restore clears those fields and increments revision again. Both actions
serialize against save and cleanup using a recipe row lock. Recipe children,
favorites, category assignments, pairings and parent relationships stay intact
during recovery. Normal browsing, category counts, selection options, related
recipes, public share pages and saves exclude deleted recipes.

`bin/purge-deleted-recipes.php` is CLI-only and protected by a database advisory
lock. It processes expired recipes in batches with one transaction per recipe.
It copies cooking associations into `deleted_recipe_cooking_history`, retaining
the recipe title, original ID/revision, servings and notes. Cooking events remain.
It detaches surviving child recipes from an expired parent, then removes the
recipe and cascading content. Only owned `media/recipes/{48 hex}.{jpg,png,webp}`
files without any surviving photo reference are removed, after database commit.
Remote URLs, unrelated paths and symlinks are retained. File-removal failures
are logged for operator cleanup. Database failures roll back the recipe's removal.

## Hosted rollout

Apply on coffeybean.com's dedicated recipe database, never the retired local
database. Do not deploy the dependent PHP before migration 010 is applied.

1. Save and verify a consistent full hosted database backup in protected storage.
2. The committed deployment workflow runs `tool/migrate-recipe-deletion.py`
   before PHP extraction. Its CLI helper backs up the database, applies only `database/migrations/010_recipe_deletion.sql` using a guarded
   migration/checksum workflow. It adds three nullable recipe fields,
   two indexes, and the cooking-history archive table. Verify existing recipes
   remain active and record the migration checksum. The runtime account may need
   temporary ALTER, CREATE and REFERENCES privileges; the helper saves and
   restores the original privileges, as in migration 009.
3. Deploy the committed backend to the verified recipe document root.
4. The workflow runs `tool/schedule-recipe-cleanup.py` after deployment to add
   and verify one daily 03:15 server-time cron entry, preserving all
   unrelated jobs:
   `/opt/cpanel/ea-php82/root/usr/bin/php /home/<account>/coffeybean.com/CoffeyTableRecipes/bin/purge-deleted-recipes.php`
5. Verify with a dedicated test recipe: delete, check normal and public visibility,
   restore and compare details. Verify the scheduled command and protected paths.
6. Develop/test Flutter locally against the hosted API. Publish Flutter only via
   the separately authorized tagged release workflow.

The deadline is exactly 30 days on the server; physical cleanup runs at the next
daily job. Backups follow their own retention policy. Offline devices keep their
copies until reconnecting. No existing recipe is deleted by applying the migration.

## Validation

`php tests/recipe_deletion.php` uses an in-memory SQLite fixture with the production
services and adapted timestamp/locking syntax. It verifies household isolation,
revision conflicts, retry behavior, restore preservation, expired restores,
normal/public visibility, category counts cooking-history retention and shared-photo protection. Python fixture tests
cover migration failures, temporary privilege restoration, job isolation and
schedule verification. It never
connects to the hosted or retired local database. PHP syntax checks also pass.
The fixture does not validate MySQL DDL or concurrent row-lock behavior; those
remain hosted rollout checks. No migration, cleanup job or hosted deployment was
run while implementing this feature.

Local validation: 29 recipe-deletion fixture checks and 38 Python deployment,
migration and scheduling fixture tests passed. Hosted validation is pending.
Recipe sharing (43 checks), migration checksums (6), invitation mail (15),
verification (14) and PHP syntax checks also passed. Mail fixtures sent no mail;
they required unsandboxed access to the existing tracked email image locally.
## Hosted rollout verified (2026-10-12)

Authorized backend push deployed commit `02d9ea3c4a6c075bc58f42faa9e55cd6258b5bef`.
GitHub Actions run 38327961963 completed successfully:
https://github.com/agawlbb/coffey-table-recipes-server/actions/runs/38327961963

The hosted CLI helper saved a full protected backup, applied migration 010, and
verified the inventory remained at 21 recipes. The workflow confirmed restoration
of the original runtime database privileges after the temporary schema grant.
Backend upload/extraction, health, CORS, protected paths and deployment receipt
verification passed. The exact daily cleanup job was verified at 03:15 server time.
Independent reads confirmed the expected hosted commit and healthy API JSON.

The hosted Flutter app was not published. Authenticated delete/restore acceptance
through the new Flutter UI remains a separate release check; this rollout did not
delete a user's recipe or run permanent cleanup manually. The scheduled expiry job
will act only on recipes whose recovery deadline has elapsed.

## Re-importing a deleted recipe (2026-10-13)

A deleted recipe previously retained the household's unique import fingerprint,
so saving the same source again failed even though the recipe was hidden. Recipe
creation now clears a matching fingerprint only from deleted recipes in the same
household, inside the new recipe's save transaction. A failed save rolls back the
transfer. Active recipes retain duplicate-import protection. Different recipes
may share a title; titles are not unique.

The deleted recipe keeps its original ID, content, and recovery deadline. It can
still be restored alongside the newly imported copy. No schema migration is
needed. PHP syntax validation and 40 in-memory deletion/import checks passed,
including active duplicate rejection, cross-household isolation, failed-save
rollback, re-import, and restoration of the original after re-import.

Hosted deployment of `fb36a91ffad127cd393e011681c8742f85fac4a0` succeeded on
2026-10-13 in GitHub Actions run 38484352321. The workflow passed PHP validation,
all behavior/automation checks, migration checksum verification, deployment
receipt/health checks, and daily cleanup schedule verification. Independent public
reads confirmed the exact hosted commit and healthy API. The migration was
already applied; no schema change or manual deletion was required. Authenticated
user re-import remains a user acceptance check; fixtures exercised the fix without
modifying personal recipes. No Flutter rebuild or hosted-app deployment was needed.
