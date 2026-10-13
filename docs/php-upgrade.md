# Hosted PHP upgrade

## Current status: blocked by hosting runtime configuration

October 13, 2026: the live switch is **not completed**. The website is healthy
on PHP **8.1.34**, SAPI **fpm-fcgi**. Original domain settings and the original
document-root `.htaccess` were restored and verified. Temporary cron jobs and
public probe files were removed from the website tree; cleanup was verified.

Hosting has PHP **8.4.26** installed. PHP 8.4 CI syntax, locked Composer/platform
checks, and all ten offline PHP behavior suites passed. Hosted PHP 8.4 CLI
checks also passed required extensions, Composer bootstrap, read-only database
access, household/recipe reads, and import preprocessing. The latest local
Python suite has 63 passing tests.

The live PHP-FPM route still executes 8.1.34 after cPanel selects ea-php84,
despite its php_fpm field reporting zero. Both the existing AddHandler and an
isolated-file explicit SetHandler were tested; neither changed the active web
runtime. Unique request nonces and X-Accel-Expires: 0 ruled out reusing the
pre-upgrade response. The cPanel setter also reports a stale addon alias with
no resolvable owner. Available account-level controls cannot complete the
actual PHP-FPM switch; provider intervention is required.

Final guarded attempt and verified rollback/cleanup:
https://github.com/agawlbb/coffey-table-recipes-server/actions/runs/38410012183
Earlier fresh-runtime diagnostic:
https://github.com/agawlbb/coffey-table-recipes-server/actions/runs/38409062343

CI and persistent maintenance commands remain on PHP 8.2. The tested follow-up
changes are saved in `tool/php84-maintenance.patch`; apply them only after the
live runtime upgrade is verified. They move CI and maintenance commands to
8.4, add a Composer platform check, and replace only the exact legacy daily
cleanup job after verifying its replacement. Its 03:15 schedule is preserved.

## Request for InMotion support

Please repair/synchronize PHP version management for **coffeybean.com only**.
The verified document root is `/home/ACCOUNT/coffeybean.com`; the recipe app is
in its `CoffeyTableRecipes` subdirectory. PHP 8.4.26 is installed and the app
passes compatibility checks and hosted CLI tests on it.

LangPHP/php_set_vhost_versions for coffeybean.com returns:
`No users correspond to the domain coffeybean.com.negaautism.org`.
It can change the reported domain version but the actual website remains
PHP 8.1.34, fpm-fcgi. cPanel's php_fpm flag is zero. Even a verified ea-php84
AddHandler and an isolated explicit SetHandler leave the web process on 8.1.

Please correct the stale addon-domain ownership/mapping and this site's active
PHP-FPM routing/pool, then switch this site to PHP 8.4. Preserve other account
domains, their runtimes, DNS, databases, and the existing recipe data. Please
confirm the actual web PHP version, rather than only the MultiPHP label.
The original settings have been restored and the site is currently healthy.

## Reviewed upgrade workflow

The owner authorized upgrading hosted PHP on October 13, 2026. The reviewed
target is PHP 8.4, conditional on cPanel availability and compatibility checks.
PHP 8.4 receives upstream security support through December 31, 2028:
https://www.php.net/supported-versions.php

`Upgrade hosted PHP` is a manual Linux workflow. Its default is read-only
inspection; applying the upgrade requires its explicit `apply_upgrade` input.
It runs PHP 8.4 syntax, locked Composer/platform checks, the existing ten offline
PHP behavior suites, and Python tests before modifying hosting settings.

The upgrade script verifies the coffeybean.com document root and installed PHP
handlers, privately backs up all vhost settings, and verifies PHP 8.4 CLI on the
host through a temporary exact-match cron job. It then changes only
coffeybean.com through LangPHP/php_set_vhost_versions. Account defaults and
other sites must remain unchanged. No package installation is attempted on
the hosting server.

A temporary random-name probe requires a random secret header (or CLI argument)
whose hash is embedded in the script. It checks extensions, Composer bootstrap,
read-only database transactions, household membership/recipe reads, public-share
data, and free DOM import preprocessing. No recipe data, mail, paid AI calls,
or schema writes are involved. Public verification covers health, authentication
rejection, localhost CORS, private-path protection, actual PHP version, an
existing public share, and a stored recipe photo when available. Firebase login
is not exercised by this probe.

Any switch or verification failure restores the prior domain handler (including
inheritance where applicable) and verifies the old runtime. Temporary cron jobs
are removed by exact command. The probe is moved outside the web document root
and its old URL must return 403/404. Settings backups and safe verification
receipts remain in protected storage; private probe recovery files stay in the
account home. No credentials or recipe contents are printed or committed.

After the verified switch, update CI and the explicit PHP binary used by recipe
maintenance scripts and the existing daily cleanup job. Preserve its 03:15
server-time schedule and all unrelated account jobs. Record actual results
below after execution.

Hosting-specific API behavior: cPanel's PHP setter encounters a stale addon
alias with no resolvable owner after processing coffeybean.com. The script
accepts only that specific alias error, and only after independent cPanel
readback confirms the requested handler. All other domain settings and the real
PHP web runtime must still pass verification. Unknown errors remain failures.
No stale alias or unrelated account domain is repaired or removed by this tool.

On this CGI hosting setup, cPanel's partial setter can also leave the document
root's PHP AddHandler directive unchanged. The script backs up and verifies
that `.htaccess` separately, requires exactly one existing EasyApache PHP
AddHandler, and changes only its handler value to ea-php84. Other directives
and unrelated document roots are preserved. Rollback restores the original
file as well as the domain setting. The host documents directory PHP handlers:
https://www.inmotionhosting.com/support/website/multiple-php-versions/

Runtime requests use unique query nonces, and the temporary probe sends
`X-Accel-Expires: 0` in addition to no-store. InMotion's proxy can ignore normal
Cache-Control headers, so a cached pre-upgrade version response must never
determine upgrade success or rollback:
https://www.inmotionhosting.com/support/server/nginx/advanced-nginx-vps-and-dedicated/

Actual web execution was PHP 8.1 FPM even when cPanel's php_fpm field was zero.
The workflow therefore preflights an explicit SetHandler on only the temporary
random probe file. Only after that file actually executes PHP 8.4 and passes
read checks does it apply a scoped FilesMatch handler to this site's PHP files.
Images/static files and other document roots are outside that override. Any
failed preflight restores the original file without switching the domain.
Apache's SetHandler directive:
https://httpd.apache.org/docs/2.4/mod/core.html#sethandler
