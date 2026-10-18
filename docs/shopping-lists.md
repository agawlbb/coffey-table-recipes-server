# Household shopping lists

`GET /households/{id}/shopping-list` returns `revision` and ordered `items`.
`POST` accepts the same shape, with the revision from the last read. Each item
has a unique `id`, a `name` (1–200 characters), a boolean `checked`, and a
`department` from `ShoppingListService::DEPARTMENTS`. Lists hold up to 500 items.
Both operations require membership in that household; every member may edit.

Writes lock the household row and compare revisions in a transaction. Stale
writes return HTTP 409 without replacing anyone else's changes. The client
reloads the current list and asks the user to retry. Failed writes retain the
last confirmed list; the add field is cleared only after success. The screen
refreshes every eight seconds while visible and on app resume.

Migration 015 adds only the household shopping list table, with cascading
household deletion. `tool/migrate-shopping-lists.py` uses the existing protected
backup, checksum, temporary permission and exact cron cleanup workflow. It runs
before dependent code deploys. No local persistent database is used.

The feature deployment workflow runs only on `codex/shopping-list` commits
containing `[deploy-shopping-list]`. It deploys backend runtime files and the
reviewed migration, never the Flutter app. Standard main deployment also runs
the migration, making later releases idempotent.

Validation: `php tests/shopping_lists.php` covers sharing between owner and
member, household isolation, stale writes, ordering, check-off, removal and
invalid inputs. The migration Python suite covers receipts, scheduling cleanup
and privilege restoration. Existing favorites and timer tests remain green.

Hosted validation (2026-10-18): feature deployment run 39129982170 succeeded
for c499da94f7f7c2fff7737bcba53fde0affaecb96. Its migration, PHP checks,
shopping-list service checks and all 107 Python checks passed. Independently
verified the matching hosted backend receipt, HTTP 200 health response, and
HTTP 401 for an unauthenticated shopping-list read. Household sharing and write
conflicts were verified with service fixtures; no live household list was
modified for testing. The hosted Flutter app was preserved.
