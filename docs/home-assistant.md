# Home Assistant household connections

Home Assistant is developed in a separate public-ready repository,
`home-assistant-coffey-table-recipes`. No Flutter build is needed.

Setup uses Firebase email/password authentication directly with Google, then
the Firebase ID token authorizes creating a household integration token. Only
household owners/admins may create, list, or revoke connections. The password,
Firebase ID token and refresh token are never persisted by the integration.
Google-only accounts must link email/password on the existing Firebase identity.

The integration token belongs to the **household**, independently of its creator.
It is deliberately not restricted to shopping-list scope: future integration
endpoints can authorize the same household credential. It does not authenticate
as an individual and is not accepted by existing account, admin, recipe, or
household management routes. New integration features must explicitly accept
this credential and derive the household from it, never from request input.

## Endpoints (under `/api/v1`)

- `GET /home-assistant/auth-config`: public Firebase web API key, loaded from
  ignored `config/home-assistant.local.json` (`{"api_key":"..."}`). This is the
  same public web-client identifier used by the app, not an authorization secret.
  No private Firebase configuration is published to Git.
- `GET /households/{id}/home-assistant-connections`: owner/admin Firebase token;
  returns connection IDs (SHA-256 digests) and creation times, never bearer tokens.
- `POST /households/{id}/home-assistant-connections` with `{}`: owner/admin Firebase
  token; returns a new `token` and `household_id`. Store the token securely.
- `DELETE /households/{id}/home-assistant-connections/{connection_id}`:
  owner/admin Firebase token; revokes only a connection belonging to that household.
- `DELETE /home-assistant/connection`: household bearer token; revokes itself.
- `GET /home-assistant/shopping-list`: household bearer token; existing shopping
  snapshot (`revision`, `items`, `departments`), including completed items.
- `POST /home-assistant/shopping-list`: same bearer token; accepts only `revision`
  and `items`. Preserves department configuration and remembered assignments;
  uses existing validation, household locking and HTTP 409 conflict protection.

The connection fixes the household server-side. A supplied household ID cannot
change its target. Shopping credentials never go through Firebase bootstrap and
never recreate removed user accounts. Deleting the household invalidates access.
Membership removal of the original installer does not revoke this household
credential; an owner/admin must revoke it deliberately.

## Storage and deployment

Random 256-bit bearer tokens have a `ctrha_` prefix. Only their SHA-256 digests
are used as filenames in protected, ignored `storage/home-assistant-connections`.
Records contain household ID, creation time, and `household_integration` scope;
never raw tokens or personal credentials. Files/directories request 0600/0700
permissions. Existing Apache rules deny HTTP access to config and storage.
These records must survive deployment and be included in protected server
backups. No database migration is needed. This file-backed store assumes the
current single-server deployment; use a shared durable store before scaling to
multiple backend instances. Tokens have no automatic expiration; revocation is
explicit. Keep backup access private and revoke credentials after compromise.

Deploy the runtime PHP and configure the public web key before installing the
integration. The normal deployment allowlist preserves private config and
connection records. Never upload service-account keys as client configuration.

## Change delivery

The integration emits the discoverable `changed` event entity and
`coffey_table_recipes_shopping_list_changed` bus event after confirmed snapshot
changes. Polling defaults to 15 seconds, adjustable from 5–300 seconds. HA writes
refresh immediately. Initial loading and identical polls do not emit events.
This is not a durable event log; changes between polls may be coalesced or missed.

### Requests to synchronize an external list

Authenticated household members may `POST {}` to
`/households/{id}/shopping-list/sync-request`. This records a household request
with an increasing `id`, `requested_at`, and `reason: shopping_list_opened`.
The app sends one when its shopping view becomes visible/active, not on polls,
item edits or ordinary rebuilds. Failures are best effort and do not block the
list. No actual external-list reconciliation is performed by this endpoint.

HA shopping-list responses include `sync_requests` containing `latest_id` and
the latest 128 `requests`. They are stored separately from list revisions, in
protected `storage/shopping-sync-requests/{household}.json`, under file locks.
No database migration is required. Preserve this directory in server backups.
Household membership gates app requests, and the existing household token fixes
which journal HA can read. No requesting user's personal data is exposed.

HA emits `coffey_table_recipes_shopping_list_sync_requested` and the separate
Shopping list synchronization event entity (`requested`). The payload includes
`household_id`, `config_entry_id`, `request_id`, `requested_at`, and `reason`.
Delivery follows the polling interval. The last consumed ID persists in the HA
config entry without reloading the integration on cursor updates. Initial setup
baselines existing history; subsequent reloads/restarts consume retained unseen
requests. Sync-only changes do not emit shopping-list-changed events. This
bounded journal is not guaranteed delivery across crashes or retention overflow.

Sync-request rollout on October 18, 2026: backend run 39148973036 succeeded for
`8ec35404908a745cc549735580e8c3164d9c124b`. Independent hosted checks confirmed
the matching receipt, health HTTP 200, unauthenticated request creation HTTP 401,
GET rejected with HTTP 405, integration reads HTTP 401, and journal files HTTP
403. No real household requests or items were created during verification.
HA run 39148980874 passed 19 tests; Flutter passed 11 focused widget checks and
analysis. The Flutter source is committed but requires an authorized app build.

## Validation

Run `php tests/home_assistant_connections.php` and `php tests/shopping_lists.php`.
Fixtures use temporary token files and in-memory SQLite, never hosted household
data. They verify token hashing, isolation, independent connections, revocation,
shopping revisions, department preservation and the existing membership path.

Deployment on October 18, 2026: run 39147995236 passed the full backend checks,
public Firebase client configuration, protected file readback and runtime deployment
for `7c2e3742b37d67611ed82bcc3386b8aeee6652cd`. Independently verified the matching
hosted receipt, health HTTP 200, unauthenticated integration reads/revocation and
connection-management HTTP 401, and protected config/storage HTTP 403. An invalid
fixture account received Firebase's expected invalid-login response, proving the
configured sign-in boundary accepts the public client identifier. No real account
credentials or household data were used. Four configuration-helper tests passed.
The separate integration passed 10 client tests and 7 real HA lifecycle tests in
Linux run 39148209297. A live HA installation and real-account sign-in were not
performed. The token remains household-wide for future integration features.
