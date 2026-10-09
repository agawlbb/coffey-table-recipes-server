# coffeybean.com hosted testing

Deployment target: `/home/hosting_account/coffeybean.com/CoffeyTableRecipes`.
This is the addon domain's directory; the account's main `public_html` serves a different domain.

Dedicated database: `hosting_account_recipes`. Dedicated user: `hosting_account_recipes`, granted SELECT, INSERT, UPDATE, DELETE on that database only. Schema work uses a separate administrative session after the user's hosting decision.

The server's ignored `config/local.php` selects production, localhost MySQL, the dedicated database, Firebase project `coffey-table-recipes`, and an explicit CORS allowlist for coffeybean.com, www.coffeybean.com, localhost, and localhost:8080. Credentials are excluded from Git and client builds.

The local PHP configuration and database were retained unchanged. Hosted and local data are independent; there is no automatic replication.

The initial copy on 2026-10-09 imported 17 tables and 187 rows. Each hosted table count matched the source. Backend code and Composer dependencies were copied, excluding local credentials and dumps. Only backend files remain deployed; the web frontend stays local. Uploaded archives were moved to cPanel Trash after extraction. Internal directories including config and vendor are blocked by Apache rules.

Verification: hosted liveness, authenticated household and recipe reads, and protection of internal paths. The host returns ModSecurity 406 to curl's default user agent; browser and Dart user agents work. Health is a liveness check, not a database check.

Before future API or schema changes, explain the change and ask whether to return app builds to the local backend. Back up the hosted database before any future migration or replacement. Do not import a fresh local dump over the hosted database without reviewing changes made since this initial copy.
