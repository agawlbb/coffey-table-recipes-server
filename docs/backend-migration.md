# Data transport moved to the VPS

Task branch `backend_migration` starts at fetched `origin/main` `5424e9d`.
Matching branches are in `coffey-table-recipes` and `coffey-table-recipes-vps`.

The database stays on InMotion. Recipe data reads/adds/edits, snapshots,
restaurants, meal planning and timer data now use the existing VPS WebSocket
service, following shopping. Their HTTP route implementations are removed;
retired data routes return 410. Read-only public recipe links in the app also
use the VPS. `share.php` stays available and reads the same database.

Signup, Firebase account provisioning, invitations/email, account administration,
child-mode PIN state and AI endpoints remain here with their existing credentials.
The new authenticated `/households/{id}/recipe-category` AI endpoint preserves
blank-recipe categorization during VPS saves. It checks household membership and
uses the existing classifier; the VPS receives a category, never an AI credential.
Shared SQL services remain here where AI, account or share-page code needs them.

New user-uploaded photos are stored and served by the VPS. Existing InMotion
photo URLs remain valid; share pages preserve VPS photo URLs stored with recipes.
VPS deployment is owned entirely by its repository. Use this repository's existing
backend deployment for the endpoint removal, with the matching app source;
no schema migration, compatibility switch or new cutover workflow is needed.

Validation includes `php tests/data_routes.php`, which exercises the actual router
without credentials or database access, plus the existing classifier and
child-mode fixtures. No app build or release is part of this task.

Household/person names and people changes also use the VPS. To preserve the
existing child-account badges, GET `/me/child-mode` includes only boolean statuses
for people in the caller's households. PINs and Firebase UIDs are not exposed.
The app caches those account-policy flags separately from data snapshots.

Verified on 2026-10-29 in the shared container:

- `php tests/data_routes.php`: actual retired recipe/meal/timer/household HTTP
  routes return 410 without credentials or database access; health, signup and AI
  routes remain available under their existing method/authentication requirements.
- `php tests/household_child_status.php`: household-scoped boolean policy data,
  privacy, nonmember exclusion, pending reset and existing snapshot checks passed.
- `php tests/blank_recipe_categories.php`: all 18 existing classifier checks passed.
- `php tests/child_mode.php`: existing PIN, account isolation, throttle and write
  policy fixtures passed. Changed PHP syntax and `git diff --check` passed.

No hosted endpoint was changed by this source preparation; no schema/data
migration, app build or deployment was performed. Branches remain unmerged.
