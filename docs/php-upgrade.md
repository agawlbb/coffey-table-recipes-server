# Hosted PHP upgrade

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
