# Account migration branch

Accepted for integration into `main` on 2026-10-31. This integration creates no
release, build or deployment; the main merge commit skips the push-triggered
deployment workflow. The completed account working branches are removed.

Current policy: child-mode flag, PIN hashes, attempts, lockout and next-login reset
state now live in the SQL users row. Apply `020_child_mode.sql` and preserve/import
the legacy files once before deployment. This branch retires `/me/child-mode` and
`/admin/users/{id}/reset-child-mode`; the app uses the VPS account WebSocket scope.
Retained shared-backend write guards read the same SQL state. Earlier descriptions
of the VPS calling InMotion policy below are superseded by this change. See the
VPS repository's `CHILD-MODE-SQL.md` for setup and deployment evidence.

Cutover completed on 2026-10-31: five columns added; three file policies imported
and verified for four SQL users, no unmatched files. Original file state remains
intact. Temporary ALTER permission was revoked and original privileges verified.
This branch's runtime index and ChildMode source were installed via authenticated
cPanel and read-back hashes matched. Account/PIN/reset HTTP routes are retired;
use the matching VPS app. No web frontend build was made. The VPS now reads policy
directly from SQL and no longer calls this site's policy endpoint. SQL PIN,
lockout, reset, isolation and route checks passed, as did 12 mocked deployment
tool tests. Deployment's CORS probe now uses retained `/health`, not retired `/me`.

The matching `feature/vps-account-auth` branches move `/me`, `/households`,
`/me/verification-email`, invitation sending and invitation responses to the VPS
account WebSocket scope. This repository returns `410 account_moved` for those
routes. Firebase still owns passwords, Google sign-in and password resets.

The implementation and private file destinations are documented in the VPS
repository's [ACCOUNT-WEBSOCKET.md](https://github.com/agawlbb/coffey-table-recipes-vps/blob/main/ACCOUNT-WEBSOCKET.md).
Reuse `config/smtp.local.json` and the service-account file selected by
`firebase_service_account_file` in `config/local.php`; defaults use
`config/firebase-admin.local.json`. Keep these files outside Git.

InMotion still stores MySQL, child-mode PIN state and account administration.
The VPS account service calls authenticated `/me/child-mode` for its authoritative
policy; preserve that endpoint and its credential/configuration. Existing SMTP
delivery still uses the authenticated mail server, with the VPS as its client.

Do not deploy this retirement until the VPS account service and the app's matching
routing are ready. Publishing a feature branch does not deploy this repository;
the normal backend deployment runs on pushes to `main`. No deployment or real
email delivery is claimed by these branch tests. `php tests/data_routes.php`
checks retired account paths and retained authenticated policy without a database.
