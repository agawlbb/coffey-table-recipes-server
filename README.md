# Coffey Table Recipes API

PHP 8.0+ with PDO MySQL. Apache requires mod_rewrite and AllowOverride for the included .htaccess rules.

## Hosted backend and optional isolated local setup

The persistent backend and MySQL database run exclusively on coffeybean.com. The outdated local coffey_table_recipes database was removed on 2026-10-11 after saving a private recovery backup. See docs/backend-deployment.md for Git-push deployment. For disposable local tests only, copy config/example.php to config/local.php and configure a dedicated test database. Local configuration is ignored. Never deploy local root credentials to public hosting.

Run migrations from the command line:

```powershell
C:\xampp\php\php.exe bin\migrate.php
```

The runner records SHA-256 checksums, refuses changed applied migrations, and serializes migration processes with a database lock. Migration SQL uses simple semicolon-separated statements; stored routines are not supported. MySQL DDL is not transactional. Back up production before migrations and investigate any partial failure before retrying.

## API

GET /CoffeyTableRecipes/api/v1/health returns service availability. This liveness endpoint does not check the database or Firebase. Identity and household endpoints require a Google-provider Firebase ID token. GET /api/v1/me bootstraps the local account and returns its households. GET/POST /api/v1/households lists or creates households. GET/POST /api/v1/households/{id}/people reads or adds household people, with owner/admin required for adding people.

Web development uses http://localhost:8080, permitted by the explicit CORS allowlist. Change allowed_origins and base_path in local configuration for deployment. CORS is not authorization.

Internal directories and Composer metadata must remain inaccessible over HTTP. The included Apache rules enforce this and should be verified on cPanel. Disable directory listings. Media will be delivered through authorized handlers; direct storage access is denied.

## Configuration and validation

Firebase Authentication verification uses firebase/php-jwt. Run composer install when preparing the backend, then configure firebase_project_id and database settings in ignored config/local.php. Recipe, household, shopping-list, sharing and timer-sync behavior is documented in docs/ and covered by the PHP fixtures and Python tooling tests. Production migrations and publishing require the hosted workflow and its authorization; they are not local validation steps. No authentication keys or service-account files belong in this repository.

The root address redirects to app/, containing the compiled Flutter web client. Build and publish it with the Flutter project's tool/publish-local-web.ps1 script. API routes remain under api/v1.


## Open-source use

This project is licensed under GNU AGPL-3.0-only. See LICENSE and NOTICE.md for
source, network-service, artwork, and dependency notices. Source repositories:
[Flutter app](https://github.com/agawlbb/coffey-table-recipes) and
[PHP backend](https://github.com/agawlbb/coffey-table-recipes-server).

Use your own Firebase project, database, hosting, email and optional AI credentials
for independent installations. Local configuration, signing material, uploaded
media, personal imports and generated packages must stay outside version control.
The default coffeybean.com endpoint is the project deployment, not a free backend
for unrelated installations. AI access and existing public recipe sharing remain
unchanged by publication.

History was sanitized before publication. Existing contributors must make a fresh
clone rather than merging old history back. Earlier validation commit hashes and
Actions links in release notes refer to private, retired pre-publication records;
version labels and factual validation results are retained.
