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
