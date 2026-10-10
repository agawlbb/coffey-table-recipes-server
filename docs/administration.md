# Account administration deployment

The user selected continued testing on coffeybean.com on 2026-10-10. These changes are prepared in source; hosted activation is pending. Keep the local development database intact.

## Activation

1. Back up the dedicated hosted database `hosting_account_recipes` and the deployed recipe backend before migrating. Never replace it with a local dump.
2. Add `firebaseauth.users.delete` to the existing `householdInvitationLookup` custom role assigned to the server service account `household-invite-lookup` in Firebase project `coffey-table-recipes`. Retain `firebaseauth.users.get`. No new key or broad Firebase administrator role is needed. This is security-sensitive permission expansion and requires action-time browser approval.
3. Apply `database/migrations/008_administrators.sql` with a schema-capable administrative connection and record its SHA-256 checksum in `schema_migrations`, following the migration runner's format. Do not alter existing migration checksums. It adds `users.account_role` and grants the initial administrator to the verified, enabled `admin@example.test` account. Bootstrap also grants that account on a later verified login if needed.
4. Deploy `index.php`, `src/bootstrap.php`, `src/HouseholdService.php`, `src/FirebaseAccountDirectory.php` and `src/AdministrationService.php` together to `/home/hosting_account/coffeybean.com/CoffeyTableRecipes`. Preserve hosted configuration, credentials and uploads. Publish the matching Flutter build under `app/`.
5. Verify authenticated `/me` returns the administrator role, ordinary accounts receive 403 on administration APIs, and the administrator window lists users. Never delete a real account merely to verify deployment. A live deletion test requires an explicitly chosen disposable account and confirmation.

## API and deletion behavior

`GET /api/v1/admin/users` returns database accounts with string `id`, `display_name`, `email`, `account_role` and boolean `disabled`. It never exposes Firebase UIDs or credentials.

`POST /api/v1/admin/users/{id}/delete` accepts JSON `{"confirm":true}` and returns `{"deleted":true}` only after Firebase deletion and SQL commit. The server rechecks the enabled administrator role, rejects self-deletion with 409, missing confirmation with 422 and unknown users with 404.

SQL cleanup runs in a transaction, clears recipe/history attribution, removes related invitations and linked household people, then removes memberships and the user. Shared household content is preserved. Firebase deletion runs after SQL statements succeed but before commit; a Firebase error rolls SQL back. Firebase and MySQL cannot share an atomic transaction: an interrupted final commit can leave the Firebase account deleted and the SQL account present. Retrying is safe because a missing Firebase account is treated as already deleted. The UI permits a 60-second request deadline for these calls.

Production bootstrap locks the Firebase UID's SQL row during provisioning. If that UID is absent, it verifies the account still exists and is enabled in Firebase before inserting. A deleted account's unexpired ID token therefore cannot recreate its SQL user. This requires existing account lookup permission for new-account provisioning. Normal existing-account requests do not add a Firebase network lookup.

Deletion removes account records entirely and does not create a tombstone. It does not prohibit registration of a new Firebase account later. Household roles are independent of the global administrator role; deleting a household owner does not automatically transfer ownership.

## Verification

Run `C:\xampp\php\php.exe tests/administration.php` against development configuration. It creates/drops a random disposable database and uses a fake remote deleter; it never deletes a real Firebase account. Coverage includes verified bootstrap, administrator authorization, confirmation, self-delete protection, missing users, remote failure rollback, cleanup, content preservation and stale-token rejection. Also run the authentication, invitation and recipe schema suites.

Firebase uses the documented [account lookup](https://docs.cloud.google.com/identity-platform/docs/reference/rest/v1/projects.accounts/lookup) and [account deletion](https://docs.cloud.google.com/identity-platform/docs/reference/rest/v1/projects.accounts/delete) endpoints with server-only OAuth credentials.
