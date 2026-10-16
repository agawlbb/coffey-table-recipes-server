# Shared recipes rollout and validation

This is a coordinated client/backend feature change. Nothing here authorizes deployment. Joe merges and deploys from his laptop. Do not push main or tags, run distribution workflows, or apply a hosted migration as part of reviewing these branches.

## Data model and authorization

Migration `011_shared_recipes.sql` adds `recipe_households`, backfills every existing recipe (including trash), and records which household owns a final-removal recovery window. The existing `recipes.household_id` becomes the immutable storage household for recipe children. Attaching a recipe does not copy it or transfer its child rows. Removed household attachments are retained as tombstones until the canonical recipe is purged.

All attachment changes lock the canonical recipe. Saves lock it and check current attachment, revision, and shared-edit confirmation before changing content. Authenticated membership is required for mutations. A second household's editor is recorded through a user foreign key rather than requiring membership in the storage household. Membership checks still precede all sensitive account, import, photo-generation, trash, restore, and administrative actions.

Non-final removal detaches only the selected household and does not start a purge. Final removal starts the existing 30-day recovery window; only that last household can restore it. Restoration does not resurrect earlier detached households. Purge rechecks that no active attachment exists while holding the recipe lock. Child content and cooking-history cleanup remain canonical. Repeated attach/delete calls are idempotent. Stale content revisions are rejected.

GET `/households/{id}/recipes` and `/households/{id}/recipes/{recipe}` and GET `/recipes/{recipe}` bypass identity parsing entirely. Missing, invalid, and expired Authorization headers therefore cannot block those routes. The household list stays household-scoped; public detail may open a live recipe outside it. Deleted recipes are excluded. Raw import text, import keys, and user attribution fields are omitted. Editor capability lookup is a separate authenticated `edit-options` endpoint.

## Deployment order — do not apply during this task

1. Review both branches and the existing unpushed client ancestor commit. Merge using Joe's usual laptop workflow only when ready. Backend main currently triggers deployment, so arrange the maintenance window before merging it.
2. Make a verified backup of the dedicated recipe database and recipe media. Verify the exact coffeybean.com application database and document root; the hosting account contains unrelated domains.
3. Disable recipe writes and pause the expired-recipe purge job. Do not run old and new writers concurrently during migration. Rehearse migration 011 and the new backend against a disposable MySQL clone first.
4. Apply the existing checksum-aware migration runner through the usual deployment process, then deploy the matching backend before reopening writes. MySQL DDL implicitly commits: a failed migration may be partially applied. Inspect the schema and migration ledger before resuming; do not blindly rerun `ALTER TABLE` statements or drop data to make them pass.
5. Validate: every live recipe has an active attachment; existing trash has `trash_household_id`; child counts and IDs are unchanged; the editor attribution FK now references `users(id)`; missing/invalid bearer tokens can browse but cannot mutate; household A cannot write through household B's URL.
6. Deploy the matching web/mobile clients. Resume purge only with the new purge code. Keep backups until cross-household editing, detachment, final deletion, restore, and expiry have passed staging checks.

Do not roll back to the old backend after sharing begins: it ignores attachments and could soft-delete content still used elsewhere. Prefer a forward fix. Any rollback needs maintenance mode and an explicit reconciliation/export of all attachments and post-migration edits. Never flatten shared content silently or drop the attachment table.

## Existing client compatibility

Existing recipe list/detail URLs and payloads remain. Added metadata is `in_household` and `household_count`. New clients submit `confirm_shared_edit: true` only after confirmation. Older clients cannot confirm shared edits and get HTTP 409 with `shared_confirmation_required`; they must update rather than bypass the warning. They can still edit singly attached recipes.

Old clients' deletion requests use the safe new server semantics, but their labels and Undo behavior do not understand detachment. A non-final delete returns `purge_after: null`; new clients undo it with `attach`. Old clients may incorrectly describe it as trash, and their restore attempt will fail safely. Do not advertise cross-household sharing until the updated client is available. New clients require the new backend, migration, and public-read routes first.

The storage household still owns canonical categories, pairings, and parent relationships. Editing these changes the canonical recipe for everyone. Creating a new variation with a parent stored in another household is currently rejected by the existing composite parent FK and ancestry validation; this needs a separate schema/relationship design before that workflow can be supported. This restriction prevents copying or corrupting existing relationships. Do not claim cross-household variation creation is supported.

The browsing preference cache contains only household IDs/names scoped to Firebase UID and API base URL. If token refresh fails, it allows public browsing of the last successfully loaded household list. It never authorizes writes or supplies account/people/admin data. A fresh installation with no successful household lookup still needs sign-in to determine its household; public links always work without one. Offline recipe data can be stale until reconnect/sync.

## HTTPS app links

Android declares an auto-verified HTTPS intent filter for `coffeybean.com/CoffeyTableRecipes/share.php`; iOS declares `applinks:coffeybean.com`. Flutter routes that URL directly to public recipe display. Adding calls the authenticated attach endpoint, including a household chooser when needed. The PHP share page remains readable without JavaScript or authentication and includes a link to the web app's shared-recipe screen.

Generate association files locally using `php tool/generate-app-links.php APPLE_TEAM_ID ANDROID_SIGNING_SHA256 NEW_OUTPUT_DIRECTORY` in the backend repo. Use the real Apple application identifier prefix/team and the fingerprint of the certificate that signs the installed Android build (Play App Signing certificate for Play distribution, not merely the upload certificate). No signing private key is needed. The generator refuses an existing output directory.

During a later authorized deployment, merge these entries into any existing domain-wide association files and serve them over HTTPS at `https://coffeybean.com/.well-known/apple-app-site-association` and `https://coffeybean.com/.well-known/assetlinks.json`, with JSON content type and no redirects or login. Do not overwrite other apps' entries. These files must be at the domain root, not under `/CoffeyTableRecipes/`. Register Associated Domains for the iOS app and regenerate provisioning profiles as needed. Until correct files and signed apps are deployed, OS app opening is not verified and web fallback is expected. Apple/Android association caches and user link preferences can affect opening.

## Timers and platform limits

Android uses `AlarmManager.setAlarmClock`, persisted keys/deadlines, a receiver and a media-playback foreground service. The first start requests notifications (Android 13+) and exact-alarm access (Android 12+); the user must retry after granting permission. Denials return an error rather than a fake running timer. Timers have distinct recipe/action keys; editing replaces the matching OS alarm, cancellation removes it, and reopening cooking restores persisted state. Boot/package-update/permission-grant receivers re-register future timers. Already elapsed timers after a shutdown are displayed as elapsed, not replayed as late alarms. Tone selection uses installed alarm tones. Sound still depends on alarm volume, DND, permissions, force-stop, and device/vendor restrictions. There is no force-stop guarantee.

iOS 26+ uses AlarmKit authorization and scheduled alerts. It uses a separate ActivityKit Live Activity rather than an AlarmKit countdown presentation. iOS 15–25 uses local notifications; these are finite notification sounds, not persistent alarm playback, and silent mode/Focus/settings may suppress them. Live Activities require iOS 16.2+, are optional, and have system limits; failure to create one does not cancel the scheduled alarm. Default, gentle, and bright tones apply to new/edited timers. Editing schedules the replacement before cancelling the previous alarm, preserving the old timer on failure. Native state restores independently of the Dart screen. Do not promise alarms while a device is off or guarantee force-stop behavior.

The new `TimerWidget` extension needs its own registered identifier/provisioning profile (`com.coffeytable.coffeyTableRecipes.TimerWidget`) before distribution. Review release signing/export automation and keep its version/build aligned with Runner. Existing workflows have not been run or updated to provision this new target. Xcode 26+ is required for AlarmKit compilation. Windows cannot compile or exercise this Swift/WidgetKit code; Mac compilation and physical-device validation are required before merging/releasing the timer work.

Official references consulted: [Android alarms](https://developer.android.com/develop/background-work/services/alarms), [Android App Links](https://developer.android.com/training/app-links), [Apple AlarmKit sample and requirements](https://developer.apple.com/documentation/alarmkit/scheduling-an-alarm-with-alarmkit), [AlarmKit scheduled alerts](https://developer.apple.com/documentation/alarmkit/alarmmanager/alarmconfiguration/alarm(schedule:attributes:stopintent:secondaryintent:sound:)), [ActivityKit](https://developer.apple.com/documentation/activitykit), [Universal Links](https://developer.apple.com/documentation/xcode/supporting-universal-links-in-your-app).

## Inspection checklist

- Create recipe in A; attach to B twice; confirm one canonical recipe and two attachments. Edit from B after confirmation; read the new content from A. Cancel confirmation and verify no write. Attach during an already-open editor; confirm the server forces the warning without losing the draft.
- Remove from A; B and the public link still work. Remove from B; public detail is 404 and B sees trash. Restore from B; A stays detached. Test stale revision, foreign household, duplicate requests, expiry, and purge versus attach/edit/restore on disposable MySQL with concurrent transactions.
- Browse list/detail with no token and a deliberately expired/invalid token. Keep writes, imports, people, trash, and admin operations protected. Reopen with a cached signed-in user and expired token; browse public content, then verify writes still require authentication.
- Test HTTPS cold/warm links on signed Android/iOS builds, outside-household add, logged-out viewing, sign-in to add, invalid/deleted IDs, and web fallback. Verify domain association status on each OS.
- Start at least two timers, lock the phone, background the app, edit/cancel one, reopen, and reboot before a future timer. Deny/revoke permissions and test muted/Focus/DND states. Test iOS 26 AlarmKit separately from the older-notification fallback. Check Live Activity countdown and cancellation with the app backgrounded. Never infer these results from Dart unit tests.
- On iPhone, search and dismiss; paste a long multiline recipe; type near its bottom with the keyboard visible; preserve line breaks; test numeric ingredient fields and modal editor sheets, including landscape and larger text.

## Local evidence

At this checkpoint: Flutter analysis passed; the full 234-test Flutter suite passed, plus two additional shared-save confirmation/race tests; Android `:app:compileDebugKotlin` passed. PHP: 18 shared-recipe fixture checks, 43 deletion checks, 79 variation relationship checks, 43 sharing-page checks, 53 source-link checks and six migration-checksum checks passed. These PHP checks use fixtures/pure functions and do not connect to a hosted database. Additional final checks are reported in the task handoff.

Not performed: MySQL migration/concurrency rehearsal, production queries/migration, iOS compilation, physical-device alarm/keyboard checks, signed app-link verification, release builds, paid Actions, deployment, or distribution. No credentials were changed.
