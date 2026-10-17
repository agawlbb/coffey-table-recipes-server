# Account-private timer sync

Migration `014_timer_sync.sql` adds installation registrations and shared timer
state. No household-wide sharing is introduced. All `/me/` endpoints require a
verified Firebase identity and the existing account bootstrap/disabled checks.

- POST `/me/timer-devices`: `installation_id` (random 32 lowercase hex),
  `platform` (`ios`/`android`), `push_token` (nullable when reception is off),
  `receive_timers` (boolean). Registration rebinds an installation on account
  switch and retires duplicate tokens. Tokens are never returned or logged.
- POST `/me/timer-devices/remove`: `installation_id`; only removes that user's
  registration.
- GET `/me/timers`: future timer state plus seven days of cancellation tombstones,
  including expired timers, to silence ringing alarms after missed stop pushes. Times are UTC epoch milliseconds.
- POST `/me/timers`: installation ID, namespaced `timer_id`, random
  `operation_id`, expected `revision` (0 for creation), `cancelled`, and for
  start/edit `title`, `deadline_ms`. Local duration is unrestricted, but sharing
  supports future deadlines up to seven days. Row locks serialize writes;
  revisions prevent stale edits. Retrying the last operation reuses its revision
  and can resend the push after a lost response. Clients reject repeated pushes.

State is committed before notification delivery. Failures return
`delivery: pending` without losing the timer. `sent` indicates an attempted FCM
delivery, **not acknowledgement of OS scheduling**. Devices recover with GET.
Delivery is bounded to twenty registrations updated in sixty days and an
eight-second fanout budget (each in-flight HTTPS request has a six-second
timeout). The origin receives mirrored edits/cancellations even if its receive
switch is off. Source devices do not receive their own push.

## Hosted sender configuration

Create a dedicated service account in `coffey-table-recipes` with only the
permission `cloudmessaging.messages.create` (a custom role avoids granting
unnecessary access). Enable Firebase Cloud Messaging API v1. Save its JSON
credential **only** in protected hosted
`config/timer-push.local.json`, or set `timer_push_service_account_file` in
ignored `config/local.php` to another protected path. Keep all credentials out
of Git. Existing `config/.htaccess` protects this directory.

Do not add messaging privileges to the existing auth lookup service account.
The sender obtains short-lived OAuth tokens using the installed JWT library
and the Firebase messaging scope. Invalid/unregistered FCM tokens are retired.
Android receives high-priority data pushes and posts a visible local result;
Apple receives an APNs alert plus content-available for starts/edits and silent
cancellation updates. Alert text is generic so a previously queued notification
does not expose a recipe title after a local sign-out. Payloads bind the Firebase
UID and receiving installation; native code rejects other accounts/devices and
ignores stale revisions and expired starts.

Apple also requires an APNs authentication key uploaded into the Firebase iOS
app registration, the app's Push Notifications capability and matching signed
provisioning. Development and production topic-specific APNs keys are installed
in Firebase, and Push Notifications is enabled on the recipe App ID as of
2026-10-17. Signed provisioning and physical-device delivery remain unverified.

## Deployment and validation

The backend workflow runs `tests/timer_sync.php` and migration fixture tests.
Before PHP deployment, `tool/migrate-timer-sync.py` uploads the additive reviewed
migration and CLI runner, saves a consistent protected SQL backup, applies only
014 under the existing migration lock, checks recipe inventory, and records its
checksum. Temporary schema privileges are restored and verified. Only its exact
temporary cron command is removed. Credentials and the Flutter hosted app are
excluded from deployment.

Local tests cover account isolation, opt-in filtering, origin updates, operation
retries, stale edits, cancellation tombstones, offline push failure after commit,
unregister ownership, installation rebinding and invalid/expired deadlines.
Persistent database changes are applied only to the hosted recipe database.

Validation on 2026-10-17: all 22 workflow PHP fixture scripts (including the new
timer checks), six migration checksum checks, and all 99 Python fixture tests
passed. No live push or email was sent by these tests. App source validation:
297 Flutter unit/widget tests passed and Flutter analysis reported no issues.

Hosted credential verification (2026-10-17): timer-push-sender uses the project custom role recipeTimerPushSender containing only cloudmessaging.messages.create. The dedicated uploaded key expires 2027-10-17; rotate it before expiration. The protected config file is mode 0600 and anonymous HTTP access returned 403. Running bin/check-timer-push.php on hosted PHP 8.4 verified OAuth and FCM validate-only authorization without delivering a notification. The exact temporary cron entry was removed, preserving the daily purge job. Both unused Google-generated download keys were deleted after verification; temporary local sender private-key files were removed. Backend 778ddb0 was verified deployed.
