# Shared recipe rollout preflight — 2026-10-16

Continued from feature branch `feature/shared-recipes-public-reads`, commit
`c3336af`. No production migration or feature deployment has been applied.

## Hosted evidence

Inspected through the existing signed-in cPanel session. The domain document
root is `/home/negaau5/coffeybean.com`; the recipe application is its
`CoffeyTableRecipes` directory. phpMyAdmin and an independent PHP CLI read both
report MariaDB `10.11.19-MariaDB` and database `negaau5_recipes`.

A one-time cron preflight ran `/opt/cpanel/ea-php84/root/usr/bin/php` and verified
PHP **8.4.26**, SAPI **cli**, PDO MySQL, mbstring, ZipArchive, the application
root, database connection, and migration ledger entries 001–010. Its output
is retained privately in `storage/shared-preflight-20261016.log` with mode 0600.
It did not print credentials or query recipe content. The temporary cron entry
was removed and its absence verified.

The original purge job remains unchanged: `15 3 * * *`, using
`/opt/cpanel/ea-php82/root/usr/bin/php` and the recipe purge entrypoint. Do not
silently substitute a different schedule during rollout.

The application database user `negaau5_recipes` has SELECT, INSERT, UPDATE,
DELETE on the recipe database. CREATE, ALTER, and REFERENCES are absent, as
confirmed by runtime SHOW GRANTS and the cPanel privilege screen. Recovery
database access has not been provisioned. Any temporary privilege expansion
must be approved before saving it, then restored and verified before service
resumes. No passwords or stored application configuration need to change.

The cPanel Terminal page failed its WebSocket handshake twice. The cron and
File Manager route successfully executed/read the isolated preflight, so a
terminal connection is not the only possible operational route.

## Fresh local validation

- Flutter analysis and all 237 Flutter tests passed in the separate app repo.
- All 83 backend Python tooling checks passed, with the PHP binary explicitly
  configured for the maintenance tests. Hosting messages in that suite are
  mocked fixture output, not live deployment evidence.
- Shared recipe fixtures: 18 passed; deletion: 43; variation relationships: 97;
  public share pages: 43; source photos: 10; migration checksums: 6.
- The variation photo fixture initially failed under sandbox temporary-file
  restrictions; the unchanged test passed outside the sandbox.

## Matching engine rehearsal

Downloaded the official portable MariaDB 10.11.19 Windows package and verified
SHA-256 `398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490`
against the vendor's published checksum. Initialized a new marked data
directory under ignored storage and bound the standalone server exclusively
to loopback port 33317. No Windows service was installed and no existing
database credentials or databases were used.

The unchanged `mysql_shared_rehearsal.php` passed **137** migration,
relationship, and concurrency checks, including ten iterations of six
two-process races. After adding the exact rehearsed version to the helper's
engine gate, `shared_rollout_rehearsal.php` passed **39** backup/restore,
preservation, and interrupted-migration failure checks. Both harnesses removed
only their generated synthetic schemas and test backups. Other database
versions remain rejected.

The separate app repo's iOS build at `61610fb` also passed on Xcode 27:
https://github.com/agawlbb/coffey-table-recipes/actions/runs/38954806173
This was unsigned compilation/package verification, not distribution or a
physical-device alarm test.

The existing rollout guide remains the operational procedure; these preflight
results do not replace its live backup, restore, maintenance, preservation,
and runtime verification requirements. The next live prerequisite is approval
for temporary recipe/recovery database privileges. No privilege changes have
been saved and no recovery database has been created.
