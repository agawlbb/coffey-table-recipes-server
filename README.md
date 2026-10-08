# Coffey Table Recipes API

PHP 8.0+ with PDO MySQL. Apache requires mod_rewrite and AllowOverride for the included .htaccess rules.

## Local setup

Copy config/example.php to config/local.php and set database credentials. Local configuration is ignored. The development database is coffey_table_recipes. Never deploy the local root credentials to public hosting.

Run migrations from the command line:

```powershell
C:\xampp\php\php.exe bin\migrate.php
```

The runner records SHA-256 checksums, refuses changed applied migrations, and serializes migration processes with a database lock. Migration SQL uses simple semicolon-separated statements; stored routines are not supported. MySQL DDL is not transactional. Back up production before migrations and investigate any partial failure before retrying.

## API

GET /CoffeyTableRecipes/api/v1/health returns service availability. This liveness endpoint does not check the database or Firebase. Identity and household endpoints require a Google-provider Firebase ID token. GET /api/v1/me bootstraps the local account and returns its households. GET/POST /api/v1/households lists or creates households. GET/POST /api/v1/households/{id}/people reads or adds household people, with owner/admin required for adding people.

Web development uses http://localhost:8080, permitted by the explicit CORS allowlist. Change allowed_origins and base_path in local configuration for deployment. CORS is not authorization.

Internal directories and Composer metadata must remain inaccessible over HTTP. The included Apache rules enforce this and should be verified on cPanel. Disable directory listings. Media will be delivered through authorized handlers; direct storage access is denied.

## Next checkpoint

Firebase Authentication verification and household provisioning are implemented with Google's maintained firebase/php-jwt library. Run composer install when deploying the backend, then configure firebase_project_id and database settings in config/local.php. Next are complete recipe migrations and development seed data. No authentication keys or service-account files belong in this repository.

The root address redirects to app/, containing the compiled Flutter web client. Build and publish it with the Flutter project's tool/publish-local-web.ps1 script. API routes remain under api/v1.

