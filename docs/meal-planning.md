# Meal planning API and rollout

The matching Flutter and backend `codex/meal-planning-v2` branches implement the
version 2 household planner. Main and release tags remain unchanged.

## API

All routes below require a signed-in household member. Recipe additions and
pairings require an active recipe-household attachment, including shared recipes
whose storage household differs from the planning household.

- `GET /households/{id}/meal-plan?from=YYYY-MM-DD&to=YYYY-MM-DD`: revision,
  household today/time_zone, dated entries in range, and all unscheduled entries.
- `POST /households/{id}/meal-plan`: revision, operation (`add`, `move`, `remove`),
  stable client-generated entry id, and nullable date. Add accepts kind (`recipe`,
  `leftovers`, `restaurant`) and recipe_id for recipes. Affected past dates require
  explicit boolean confirm_past. One move covers both old and new dates.
- `GET .../meal-plan/pairings/{recipeId}`: existing canonical recipe pairings,
  limited to currently accessible recipes. The client excludes dishes on the day.
- `GET .../meal-plan/ingredients?from=...&to=...`: structured ingredient rows for
  each scheduled recipe occurrence, today or later, up to one year. Unavailable
  recipes reject collection; placeholders and unscheduled recipes are excluded.
- `GET .../meal-plan/history?from=...&to=...`: past dated recipe snapshots in
  planned_history, existing cooking events and deleted-recipe archives in
  recorded_history. Legacy event filtering respects the household timezone.

The initial household timezone is America/New_York. Calendar dates have no
time-of-day. The server determines today; clients cannot bypass past confirmation
by submitting their own today. Dated entries automatically become history as the
household day advances. Historical edits revise history instead of adding
duplicate cooking events. Recipe identity/title/revision snapshots survive purge;
the optional live recipe foreign key becomes null. Removed/unavailable recipes
retain the planned title but do not expose their current content or photos.

Every operation atomically increments the household revision in a transaction.
A stale revision returns 409 plan_conflict; missing past confirmation returns 409
past_confirmation_required. Both roll back without changing entries/revision.
Lost write responses are not automatically replayed.

## Additive hosted migration

016_meal_plans.sql creates only meal_plans and meal_plan_entries. The dedicated
CLI migration verifies the exact recipe domain/database, takes a protected full
backup, uses the existing migration lock/checksum ledger and verifies the recipe
inventory. The cPanel runner temporarily grants only needed schema privileges if
the normal user lacks them, then restores and verifies its original privileges.
Credentials, backups and private receipts remain outside Git.

The branch-specific Deploy meal planner backend workflow runs only when its head
commit contains [deploy-meal-plan]. [verify-meal-plan] runs verification only,
skipping migration and publication. It validates PHP, isolated SQLite/MySQL
fixtures and deployment tests before migrating and publishing the backend package.
It does not package or publish Flutter. Backend runtime source is read back and
verified, and the new HTTP route must reject unauthenticated requests.

Run fixture checks with `php tests/meal_plans.php`; MySQL DDL/CAS/purge checks run
only against the explicitly configured disposable CI database. Existing history,
sharing and shopping regression tests remain intact. Python migration/deployment
tests use mocks and send no real hosting requests.

## Verified 2026-10-22

Backend runtime `e8c9042c0b595cc0e2ed115cc24a06279298569d` was published from
the feature branch. Hosted migration 016 completed with a protected backup;
the existing 56-recipe inventory was unchanged and temporary schema permissions
were restored/verified. Authenticated service reads for the plan, history,
ingredients and existing pairings passed without modifying a household plan.

The initial post-publication HTTP check failed before the new route became ready;
the subsequent direct request correctly returned 401 authentication_required.
Readiness now retries a bounded set of temporary/router-transition statuses and
rejects unauthenticated success immediately. Verification-only run
[39876718522](https://github.com/agawlbb/coffey-table-recipes-server/actions/runs/39876718522)
passed, including isolated MySQL migration/CAS/purge tests and all 118 Python
tests; migration and redeployment were skipped. PHP fixture regressions passed,
including 23 planner checks, recipe sharing/deletion and shopping-list behavior.
No Flutter builds, hosted frontend changes, release tags or main merges occurred.


## Main integration (2026-10-23)
Merged backend main `0dad2b1ef28682881bf206c493178064e3248124` into the meal-planning feature history. Shared runtime code already contained the main snapshot and save-receipt work; only the main compatibility-validation record was missing. Preserved the planner routes, complete snapshot integration, migrations and deployment guard. Local receipt, snapshot (26 queries), meal-plan (23 checks), shopping and shared-recipe fixtures passed; all 121 mocked Python tool tests passed. No schema changes, hosted deployment or app build performed for this integration.
