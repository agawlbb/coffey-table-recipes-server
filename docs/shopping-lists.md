# Household shopping lists

`GET /households/{id}/shopping-list` returns `revision` and ordered `items`.
`POST` accepts the same shape, with the revision from the last read. Each item
has a unique `id`, a `name` (1–200 characters), a boolean `checked`, and a
`department` from `ShoppingListService::DEPARTMENTS`. Lists hold up to 500 items.
An empty department string represents an unassigned item. This is distinct from
the named Other department. Existing department values remain unchanged. The
client displays unassigned items first and new items use the empty string.
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

The retained feature deployment workflow is limited to `codex/shopping-list`
commits containing `[deploy-shopping-list]`; that branch is retired. Do not
recreate it for routine work. Standard main deployment runs the migration
idempotently before publishing backend runtime files, never the Flutter app.

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

Unassigned-item deployment (2026-10-18): run 39137830097 succeeded for
c41a0eb14fff7bf3e365de8168ca15a085037266. Independently verified the matching
hosted backend receipt. The workflow passed its PHP shopping-list checks and
107 Python checks. Empty department strings require no database schema change.
The Flutter source has 12 passing focused shopping-list tests; these app changes
still require an explicitly authorized local web rebuild.

Household department settings are stored alongside items in a JSON
envelope in the existing shopping_lists.items column. Legacy item-array rows
remain readable; item writes that omit departments preserve the current names
and order. GET responses include departments. A POST may include departments
and items together, using the same revision check and household membership.
Names must be unique ignoring case, nonempty, trimmed, at most 60 characters;
there may be up to 50 departments. Empty configurations are supported.
Renaming remaps assigned items in the same write; removing a department moves
its items to the unassigned group. No schema migration is required.

Validation: PHP fixtures cover custom names, shared configuration, preservation
by item-only clients, duplicate/invalid names, stale revisions, household
isolation, removing assignments, and reading legacy stored lists.

Hosted configuration validation (2026-10-18): deployment run 39141016652
completed successfully for a26438d669c5d3b60a1eaddc0486bd5738278b53,
including PHP fixtures, 107 Python checks, idempotent schema verification and
runtime deployment. Independently verified the matching hosted receipt, HTTP
200 health and HTTP 401 unauthenticated shopping-list access. No live household
items were modified during verification and no hosted Flutter app was deployed.

Household item department memory (2026-10-18): the existing shopping-list JSON
envelope now retains up to 1,000 recently taught normalized item names per
household. Membership checks, household row locking, revision comparison, items,
configuration and associations are part of the same transaction. Clients cannot
supply association metadata. GET and POST keep the existing public response
shape; only newly added unassigned items are classified by the server. Existing
items are not moved when a different item teaches a preference. Current assigned
items seed older household lists on their next successful write. Removal and
check-off do not erase the memory; an explicit move to unassigned remembers that
choice. Correcting a close spelling updates its closest stored names as well.

Matching folds case and common Latin accents, removes whitespace/punctuation,
and preserves letters/digits across scripts. Latin typo matching accepts one
edit for names of at least five characters (four when length changes), two for
names of at least ten, adjacent transpositions from four characters, and common
plural endings. Exact normalized matches take priority. Equally close matches
in different departments stay unassigned, as do unrelated short words or names
with different numeric sizes. Household metadata is capped to bound lookup work.

Department renames may include department_renames mapping removed names to
valid replacement departments; the app sends this to preserve associations for
items no longer in the list. Removed departments drop their old associations.
No schema migration is needed. PHP fixtures cover persistence after removal,
all household members, isolation, stale edits, explicit clearing/corrections,
spacing/case/accents/typos/plurals, ambiguous matches, numeric sizes, renaming
without live items, deletion, legacy seeding and the 1,000-entry limit.

Hosted association validation (2026-10-18): main deployment run 39143008423
completed successfully, including PHP fixtures, Python deployment checks and
hosted prerequisite checks. Independently verified the hosted receipt for
e3a510a1a486fba6fadab6f23bdd90a34622c022. A further local fixture confirms
an assigned item in legacy array storage seeds automatic placement of a new
item with a differently capitalized name. All local PHP fixtures pass. No live
household list was modified for testing and no Flutter app was deployed.
