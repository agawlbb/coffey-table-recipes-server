# Account migration branch

The matching `feature/vps-account-auth` branches move `/me`, `/households`,
`/me/verification-email`, invitation sending and invitation responses to the VPS
account WebSocket scope. This repository returns `410 account_moved` for those
routes. Firebase still owns passwords, Google sign-in and password resets.

The implementation and private file destinations are documented in the VPS
repository's [ACCOUNT-WEBSOCKET.md](https://github.com/agawlbb/coffey-table-recipes-vps/blob/feature/vps-account-auth/ACCOUNT-WEBSOCKET.md).
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
