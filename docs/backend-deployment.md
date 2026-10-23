# Backend Git-push deployment

The persistent API and MySQL database are hosted exclusively on coffeybean.com.
The local PHP checkout is source code, not an alternate persistent backend.

Pushing `main` to `agawlbb/coffey-table-recipes-server` normally runs
`.github/workflows/deploy-backend.yml`. Other branches do not deploy. The workflow
validates PHP, installs locked production Composer dependencies, runs mail and
deployment tests, and uploads an allowlisted backend package using the cPanel API.
It deploys only to `/home/hosting_account/coffeybean.com/CoffeyTableRecipes`.
The workflow can also be run manually on `main` to retry a failed deployment.

For an explicitly no-deployment documentation/history cleanup, run the relevant
validation locally and include `[skip ci]` in the pushed head commit. GitHub's
[skip instructions](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/skip-workflow-runs)
prevent push-triggered workflows from running; they do not authorize skipping
validation of runtime changes or weaken branch protection. Inspect triggers
before every push, retain the workflow configuration, and verify no deployment
was queued. Do not dispatch a workflow during cleanup. Without the skip
instruction, a main push can contact hosting, run migrations and publish.

## One-time credentials

Set these encrypted GitHub Actions repository secrets in the **server** repository:

- `CPANEL_USER`: the username associated with the cPanel API token.
- `CPANEL_API_TOKEN`: a cPanel API token authorized to upload and extract files.

The frontend repository's secrets are separate and cannot be read back or
automatically copied by GitHub. Keep tokens out of source files, commands and logs.
Use an expiry date and renew the token before expiry. cPanel tokens may have broad
account access; the deployment script restricts destinations to the recipe directory.

## Deployment boundaries

The package includes tracked runtime PHP, mail assets, Apache protections,
Composer metadata, migration SQL/CLI tools, and installed production dependencies.
It excludes hosted/local credentials, SMTP and Firebase configuration, database
backups, user media, Flutter builds, documentation and tests. Extraction preserves
those excluded hosted files. No files are automatically deleted; deliberately
removed runtime files require a separate reviewed cleanup. Existing uncommitted
changes are never sent: GitHub builds the pushed commit in a fresh checkout.

Database migrations are **not** normally run by pushes. Back up the hosted database and
apply reviewed migrations separately. Hosted data is never imported or replaced
by this workflow. Flutter releases retain their separate explicitly authorized,
tagged deployment process.

The reviewed household-image migration is an explicit exception: the
`household_image_migration` manual input or `[household-image-migration]` commit
marker runs only migration 009 before deploying its dependent code. A CLI-only
script verifies the exact recipe directory and dedicated account recipe database,
creates a complete consistent SQL snapshot in protected storage, then adds the
disabled-by-default column and records its checksum. A temporary cPanel cron job
executes it; only the exact matching job is removed afterward. Other cron entries
and hosted databases are retained. Errors stop deployment. Backup files contain
private data and must stay out of Git; restore into an empty recovery database for
review rather than replacing live data automatically.

The hosted runtime database user deliberately lacks schema privileges. If its
reviewed ALTER fails with error 1142, the migration helper saves the original
privileges in protected storage, temporarily adds only ALTER and CREATE on the
dedicated recipe database, retries the backed-up migration, and restores/verifies
the original privileges in a finally block. It grants no DROP or global permissions.
An unexpected user identity or privilege response stops without changing privileges.

## Verification and recovery

After extraction, the workflow checks hosted API liveness, localhost CORS, and
403 protection for credentials, dependencies and the protected upload archive.
It then publishes `backend-version.json` with the commit and package digest and
reads it back. Liveness does not prove authenticated database operations; verify
the changed feature on the hosted API after each behavioral change.

Packages remain in protected hosted `storage/` for recovery. An interrupted
extraction can leave a partial deployment; review the failed Actions run and retry
or redeploy the last known good commit. Deployment is an in-place extraction, not
an atomic directory swap. The receipt is only updated after runtime checks pass.
Old packages can be removed in a deliberate storage cleanup.

## Local database retirement (2026-10-11)

Saved `storage/local-retired-20261011.sql.dump` outside Git, verified 17 CREATE
TABLE definitions and the dump completion marker, then dropped only the local
`coffey_table_recipes` database at 127.0.0.1. Verified it was absent from SHOW
DATABASES and the other local databases remained present. Backup SHA-256:
`B97C5E9CE53A547E4A784A70332533506950D73947D0F1EE7AB845762FE3B4F0`.
MySQL itself remains installed for unrelated projects and disposable tests.

The hosted database was not changed. Restore the backup only into an explicitly
chosen recovery database; never import it over the current hosted data.

Local validation: ten deployment packaging/failure-path tests, 15 invitation
email checks and 14 verification checks passed. Tracked PHP files passed syntax
checks. Mail checks use fixtures and send no email. Live deployment validation
will be recorded after repository secrets are configured and the first run passes.
The pushed workflow's GitHub runs passed syntax, locked Composer installation,
mail checks, deployment tests and the unauthenticated cPanel TLS preflight. Initial
publishing attempts stopped because deployment secrets had not both been saved.

After both secrets were saved, a read-only diagnostic on the isolated
`codex/backend-auth-check` branch returned HTTP 401 and confirmed that CPANEL_USER
did not equal the hosting account name `hosting_account`. After the user corrected that
secret, run 38141851141 attempt 2 passed on 2026-10-11: cPanel authenticated the
token and allowed reading the verified recipe directory. This diagnostic never
uploads files; its deploy job is skipped on the diagnostic branch.

The user declined pushing main while recipe editing/public-page work continues.
Do not rerun the older main deployment during that work, since it could overwrite
newer hosted runtime files. The local deployment diagnostic fixes are committed,
but the pending main commits must be published only after the user authorizes
that work. Database retirement is complete; automated live deployment remains
unverified until an authorized current-main run passes. Credentials were verified;
no new deployment was performed during that diagnostic.

## Successful push deployment (2026-10-11)

The user subsequently requested deployment. Pushed the current committed backend
through `d0a2ae227e48ad70ea6c6e6fab0919eff4cbdf67`, including the recipe editor and
current public-page rendering changes. GitHub Actions run 38142772752 completed
successfully: PHP syntax, locked Composer installation, mail checks, all ten
deployment tests, cPanel TLS, upload/extraction, API health, localhost CORS,
protected-path checks and deployment receipt verification passed.

Independent public requests verified the exact hosted receipt commit, healthy
API JSON, and the existing Mint Chip Ice Cream public recipe page (HTTP 200).
No database migration or data import was performed; credentials, uploaded media
and the hosted Flutter app were preserved. Authenticated recipe save behavior
still requires its own feature verification; these deployment checks do not
perform production saves. Backend pushes to main now deploy automatically.

## Recipe recovery migration 010

The recipe-recovery backend depends on migration 010. Deployment now always runs
`tool/migrate-recipe-deletion.py` before extracting PHP. It skips an already
recorded matching checksum; on first use it saves a full consistent protected
backup and applies only the reviewed recovery migration to the dedicated recipe
database. Temporary ALTER, CREATE and REFERENCES privileges are restored and
verified. The backup includes explicit non-generated columns for safe restore.

After successful deployment, `tool/schedule-recipe-cleanup.py` verifies one daily
03:15 server-time CLI cleanup job. It changes only the exact recipe cleanup
command and preserves unrelated account jobs. See recipe-deletion.md for recovery
behavior, history retention and hosted acceptance checks. This automation has
been fixture-tested but has not yet been run on the host.

Recipe recovery rollout completed on 2026-10-12 in run 38327961963. Migration 010
was backed up and applied successfully; all 21 existing recipes were retained,
original runtime privileges restored, backend commit 02d9ea3 verified publicly,
and the daily 03:15 server-time cleanup schedule verified. See recipe-deletion.md
for the validation record and the remaining authenticated Flutter release check.

## Household database snapshots

Authenticated `GET /households/{id}/snapshot` returns one consistent household
text snapshot (schema 1), including complete recipe details, category catalog,
favorites, people, shopping list and editor catalog. The planner branch includes
its complete calendar and history. Images are references only. Import source text
and actor bookkeeping stay excluded. Membership is checked before any data read.
Queries are batched by table (18 reads on main, independent of recipe count).
`?version=SHA256` returns a small `unchanged` acknowledgement when all snapshot
content is identical, including recipe instructions and ingredients.

The client validates and persists a complete generation before making it ready.
No migration is needed. Fixture checks cover access isolation, complete text,
private-source exclusion, stable/change-sensitive hashes and fixed query count.
All existing backend workflow PHP fixtures and 111 mocked Python checks passed.

The hosted runtime already includes meal planning. Shared main source is pushed
with CI skipped after equivalent local validation so it cannot replace that
runtime with the older main router. The same patch is deployed from the planner
branch through its existing deployment workflow, preserving its routes.

Snapshot publication verified on 2026-10-23: feature runtime
`e2bc05982ab3a29a2fce9692a507b2818dbab51d` deployed successfully in Actions
run39991103386, including isolated MySQL planner checks, snapshot fixture and118
mocked Python checks. The public receipt matches that source commit; an
unauthenticated GET to the snapshot endpoint correctly returns401. Existing
planner routes remain available. Snapshot fixture query count is26 on this
branch and18 on main, independent of the number of recipes. No new schema was
introduced. An optional privileged live-data CLI probe was blocked by automatic
approval review and not executed, so authenticated full-payload verification on
an installed app remains outstanding. This documentation-only push skips CI;
the deployed runtime remains the successful source commit above.
