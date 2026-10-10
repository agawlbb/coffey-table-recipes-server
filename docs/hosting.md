# coffeybean.com hosted testing

Deployment target: `/home/hosting_account/coffeybean.com/CoffeyTableRecipes`.
This is the addon domain's directory; the account's main `public_html` serves a different domain.

Dedicated database: `hosting_account_recipes`. Dedicated user: `hosting_account_recipes`, granted SELECT, INSERT, UPDATE, DELETE on that database only. Schema work uses a separate administrative session after the user's hosting decision.

The server's ignored `config/local.php` selects production, localhost MySQL, the dedicated database, Firebase project `coffey-table-recipes`, and an explicit CORS allowlist for coffeybean.com, www.coffeybean.com, localhost, and localhost:8080. Credentials are excluded from Git and client builds.

The local PHP configuration and database were retained unchanged. Hosted and local data are independent; there is no automatic replication.

The initial copy on 2026-10-09 imported 17 tables and 187 rows. Each hosted table count matched the source. Backend code and Composer dependencies were copied, excluding local credentials and dumps. Only backend files remain deployed; the web frontend stays local. Uploaded archives were moved to cPanel Trash after extraction. Internal directories including config and vendor are blocked by Apache rules.

Verification: hosted liveness, authenticated household and recipe reads, and protection of internal paths. The host returns ModSecurity 406 to curl's default user agent; browser and Dart user agents work. Health is a liveness check, not a database check.

## Browser CORS headers

The recipe `.htaccess` clears inherited CORS headers from Apache's `onsuccess` table when PHP runs outside mod_php. CGI PHP sends its own allowlisted headers through the `always` table; `Header always unset` removes those headers and prevents the localhost browser from loading households even when the API answers OPTIONS with 204. Local XAMPP mod_php sends headers through `onsuccess` and inherits wildcard headers through `always`, so the local cleanup targets `always` instead. See [Apache's Header documentation](https://httpd.apache.org/docs/2.4/mod/mod_headers.html#header).

After deploying `.htaccess`, verify an OPTIONS request to `/CoffeyTableRecipes/api/v1/me` with `Origin: http://localhost`, request method GET, and request headers `authorization,content-type`. Expect 204 and exactly one `Access-Control-Allow-Origin: http://localhost`, plus allowed method and header lists. Check a disallowed origin receives no allow-origin header, then reload the signed-in local web app and verify household loading. Use a browser user agent for command-line hosted checks.

On 2026-10-09 the missing hosted headers were reproduced and the correction was deployed through the signed-in cPanel File Manager to the verified recipe directory. Hosted OPTIONS returned 204 with exactly one localhost allow-origin header; an unknown origin received none, and hosted health returned healthy JSON. The signed-in local web app successfully loaded the household recipe list in Chrome. Local XAMPP also passed allowed and disallowed origin preflight checks. No API routes, schema, database contents, or app backend selection were changed.

Before future API or schema changes, explain the change and ask whether to return app builds to the local backend. Back up the hosted database before any future migration or replacement. Do not import a fresh local dump over the hosted database without reviewing changes made since this initial copy.

## Household management API (2026-10-10, prepared locally)

The user selected continued testing on coffeybean.com. The app backend URL remains unchanged. Deploy the updated `index.php` and `src/HouseholdService.php` together to the verified recipe directory before testing hosted household rename. This change needs no database migration.

`POST /api/v1/households/{id}/name` accepts JSON `name` and returns the renamed `household` (`id`, `name`, `role`). It requires authenticated owner/admin membership; outsiders receive 404 and ordinary members receive 403. Blank names or names over 200 characters are rejected.

Household creation now locks the account row and rejects accounts with any existing household membership with HTTP 409. The database still allows multiple memberships, and existing memberships are retained. Household lists are ordered by ID so the one-household UI consistently uses the oldest household, including after renaming.

Local validation: 39 authentication/household checks and 55 recipe schema checks passed against disposable databases; PHP syntax checks passed. The hosted API has not been updated as part of this source change.

## Invitation setup (2026-10-10, deployment pending)

The user retained coffeybean.com. The mailbox `coffeytablerecipies@coffeybean.com` was created, and authenticated SMTP over TLS to `mail.coffeybean.com:465` passed without sending email. SMTP credentials are in ignored `config/smtp.local.json`.

The dedicated `household-invite-lookup` service account has the project custom role `householdInvitationLookup`, containing only `firebaseauth.users.get`. Its JSON credential is in ignored `config/firebase-admin.local.json`; a live account lookup succeeded. See [invitation setup](household-invitations.md).

Local verification passed 32 invitation checks and the existing 39 authentication/household and 55 recipe checks. The Flutter app passed analysis and 23 tests, was rebuilt for localhost, and its email dialog was inspected in Chrome.

Hosted database migration and code/credential deployment remain pending. cPanel Terminal could not establish its WebSocket connection. phpMyAdmin export of `hosting_account_recipes` was blocked by Chrome with `ERR_BLOCKED_BY_CLIENT`; no hosted schema change was made. Secure a hosted backup before applying migration 007 and updating the backend. The hosted web app also needs the current build so invitation email links reach the acceptance UI.

## Invitation deployment (2026-10-10)

Deployment completed through the built-in browser. Before migration, phpMyAdmin exported a 134,146-byte backup containing 19 table definitions, 14 data INSERT statements, and the final COMMIT. The backup remains outside Git in `storage/hosted-before-invitations-20261010.sql.dump`. Migration 007 completed successfully with all three statements, including its checksum entry in `schema_migrations`.

Updated backend files, Composer dependencies, and the current Flutter web build were extracted to the verified application directory. Existing hosted `config/local.php` was preserved. SMTP and Firebase credentials were deployed with permissions 0600; both credential URLs returned 403. The upload archive was moved to recoverable cPanel Trash outside the public web root.

Hosted health returned 200 with status `ok`; localhost CORS preflight returned 204 with the expected allow-origin header. The hosted app loaded its sign-in screen without console errors. A guarded one-time CLI diagnostic, invoked using cPanel Cron Jobs because Terminal's WebSocket remained unavailable, passed SMTP TLS authentication and Firebase email lookup without sending email. The temporary cron entry was removed, and cPanel showed no cron jobs afterward.

cPanel Email Deliverability reported properly configured SPF, DKIM, DMARC, and PTR for coffeybean.com. These authorize domain mail but do not guarantee recipient inbox delivery. A real email delivery test and authenticated hosted invitation UI verification remain pending the user's sign-in and choice of test invitee. Local automated validation remains as recorded above; no live invitations were created during deployment.
