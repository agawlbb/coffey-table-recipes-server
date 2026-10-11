# Backend Git-push deployment

The persistent API and MySQL database are hosted exclusively on coffeybean.com.
The local PHP checkout is source code, not an alternate persistent backend.

Pushing `main` to `agawlbb/coffey_table_recipes_server` runs
`.github/workflows/deploy-backend.yml`. Other branches do not deploy. The workflow
validates PHP, installs locked production Composer dependencies, runs mail and
deployment tests, and uploads an allowlisted backend package using the cPanel API.
It deploys only to `/home/hosting_account/coffeybean.com/CoffeyTableRecipes`.
The workflow can also be run manually on `main` to retry a failed deployment.

## One-time credentials

Set these encrypted GitHub Actions repository secrets in the **server** repository:

- `CPANEL_USER`: the username associated with the cPanel API token.
- `CPANEL_API_TOKEN`: a cPanel API token authorized to upload and extract files.

The frontend repository's secrets are separate and cannot be read back or
automatically copied by GitHub. Keep tokens out of source files, commands and logs.
Use an expiry date and renew the token before expiry. cPanel tokens may have broad
account access; the deployment script restricts destinations to the recipe directory.

## Deployment boundaries

The package includes tracked runtime PHP, mail assets, Apache protections,
Composer metadata, migration SQL/CLI tools, and installed production dependencies.
It excludes hosted/local credentials, SMTP and Firebase configuration, database
backups, user media, Flutter builds, documentation and tests. Extraction preserves
those excluded hosted files. No files are automatically deleted; deliberately
removed runtime files require a separate reviewed cleanup. Existing uncommitted
changes are never sent: GitHub builds the pushed commit in a fresh checkout.

Database migrations are **not** run by pushes. Back up the hosted database and
apply reviewed migrations separately. Hosted data is never imported or replaced
by this workflow. Flutter releases retain their separate explicitly authorized,
tagged deployment process.

## Verification and recovery

After extraction, the workflow checks hosted API liveness, localhost CORS, and
403 protection for credentials, dependencies and the protected upload archive.
It then publishes `backend-version.json` with the commit and package digest and
reads it back. Liveness does not prove authenticated database operations; verify
the changed feature on the hosted API after each behavioral change.

Packages remain in protected hosted `storage/` for recovery. An interrupted
extraction can leave a partial deployment; review the failed Actions run and retry
or redeploy the last known good commit. Deployment is an in-place extraction, not
an atomic directory swap. The receipt is only updated after runtime checks pass.
Old packages can be removed in a deliberate storage cleanup.

## Local database retirement (2026-10-11)

Saved `storage/local-retired-20261011.sql.dump` outside Git, verified 17 CREATE
TABLE definitions and the dump completion marker, then dropped only the local
`coffey_table_recipes` database at 127.0.0.1. Verified it was absent from SHOW
DATABASES and the other local databases remained present. Backup SHA-256:
`B97C5E9CE53A547E4A784A70332533506950D73947D0F1EE7AB845762FE3B4F0`.
MySQL itself remains installed for unrelated projects and disposable tests.

The hosted database was not changed. Restore the backup only into an explicitly
chosen recovery database; never import it over the current hosted data.

Local validation: eight deployment packaging/failure-path tests, 15 invitation
email checks and 14 verification checks passed. Tracked PHP files passed syntax
checks. Mail checks use fixtures and send no email. Live deployment validation
will be recorded after repository secrets are configured and the first run passes.
