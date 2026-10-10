# Account administration deployment

The user selected continued testing on coffeybean.com on 2026-10-10 and approved Firebase deletion permission and hosted deployment. Hosted activation is complete. The local development database remains intact.

## Activation

1. Back up the dedicated hosted database `hosting_account_recipes` and the deployed recipe backend before migrating. Never replace it with a local dump.
2. Add `firebaseauth.users.delete` to the existing `householdInvitationLookup` custom role assigned to the server service account `household-invite-lookup` in Firebase project `coffey-table-recipes`. Retain `firebaseauth.users.get`. No new key or broad Firebase administrator role is needed. This is security-sensitive permission expansion and requires action-time browser approval.
3. Apply `database/migrations/008_administrators.sql` with a schema-capable administrative connection and record its SHA-256 checksum in `schema_migrations`, following the migration runner's format. Do not alter existing migration checksums. It adds `users.account_role` and grants the initial administrator to the verified, enabled `admin@example.test` account. Bootstrap also grants that account on a later verified login if needed.
4. Deploy `index.php`, `src/bootstrap.php`, `src/HouseholdService.php`, `src/FirebaseAccountDirectory.php` and `src/AdministrationService.php` together to `/home/hosting_account/coffeybean.com/CoffeyTableRecipes`. Preserve hosted configuration, credentials and uploads. Publish the matching Flutter build under `app/`.
5. Verify authenticated `/me` returns the administrator role, ordinary accounts receive 403 on administration APIs, and the administrator window lists users. Never delete a real account merely to verify deployment. A live deletion test requires an explicitly chosen disposable account and confirmation.

## API and deletion behavior

`GET /api/v1/admin/users` returns database accounts with string `id`, `display_name`, `email`, `account_role` and boolean `disabled`. It never exposes Firebase UIDs or credentials.

`POST /api/v1/admin/users/{id}/delete` accepts JSON `{"confirm":true}` and returns `{"deleted":true}` only after Firebase deletion and SQL commit. The server rechecks the enabled administrator role, rejects missing confirmation with 422 and unknown users with 404. Self-deletion is allowed.

SQL cleanup runs in a transaction, clears recipe/history attribution, removes related invitations and linked household people, then removes memberships and the user. Shared household content is preserved. Firebase deletion runs after SQL statements succeed but before commit; a Firebase error rolls SQL back. Firebase and MySQL cannot share an atomic transaction: an interrupted final commit can leave the Firebase account deleted and the SQL account present. Retrying is safe because a missing Firebase account is treated as already deleted. The UI permits a 60-second request deadline for these calls.

Production bootstrap locks the Firebase UID's SQL row during provisioning. If that UID is absent, it verifies the account still exists and is enabled in Firebase before inserting. A deleted account's unexpired ID token therefore cannot recreate its SQL user. This requires existing account lookup permission for new-account provisioning. Normal existing-account requests do not add a Firebase network lookup.

Deletion removes account records entirely and does not create a tombstone. It does not prohibit registration of a new Firebase account later. Household roles are independent of the global administrator role; deleting a household owner does not automatically transfer ownership.

## Verification

Run `C:\xampp\php\php.exe tests/administration.php` against development configuration. It creates/drops a random disposable database and uses a fake remote deleter; it never deletes a real Firebase account. Coverage includes verified bootstrap, administrator authorization, confirmation, self-delete protection, missing users, remote failure rollback, cleanup, content preservation and stale-token rejection. Also run the authentication, invitation and recipe schema suites.

Firebase uses the documented [account lookup](https://docs.cloud.google.com/identity-platform/docs/reference/rest/v1/projects.accounts/lookup) and [account deletion](https://docs.cloud.google.com/identity-platform/docs/reference/rest/v1/projects.accounts/delete) endpoints with server-only OAuth credentials.

## Hosted deployment on 2026-10-10

Before migration, phpMyAdmin exported the dedicated recipe database. The verified 137,948-byte backup contains 20 table definitions, 15 INSERT statements and COMMIT and is retained outside Git as `storage/hosted-before-administrators-20261010.sql.dump`. The old deployed PHP index and src directory are backed up in protected `storage/before-administrators-20261010.zip` on the host.

Installation-specific account details removed; identify your own account and household before maintenance.

The coordinated backend and matching web build were extracted to the verified coffeybean.com recipe directory. Hosted configuration, credentials and uploaded content were preserved. The upload archive was moved to recoverable cPanel Trash and its former public URL returns 404. The PHP backup and Firebase credential URLs return 403. Both hosted and localhost web copies now contain the administrator UI and continue using the hosted API.

Hosted health returns 200. Unauthenticated administrator listing and deletion routes return 401. The hosted app renders its sign-in screen with no recorded browser errors. The database confirms the requested administrator role. Signed-in hosted verification confirmed the administrator menu, session restoration in a separate administration tab, user listing, disabled self-delete and permanent-delete confirmation with cancellation, with no recorded browser errors. The built-in browser did not expose the requested popup; the administration route was verified in a separately opened tab. No live user deletion was performed. Local verification before deployment passed Flutter analysis, 33 Flutter tests, web/Android builds and 24 administrator, 51 authentication, 57 invitation and 55 recipe-schema checks.

## Invitation-associated person cleanup

Account deletion resolves table people before removing invitations. In addition to people linked by user ID, it removes unlinked people identified by an invitation to the account email or an accepted invitation responded to by that user. Entries linked to another account and unrelated people with matching names are preserved. Invitations referencing the selected people are removed first to satisfy foreign keys. Firebase failure rolls this cleanup back with the account transaction.

On 2026-10-10 the user reported Val and Joe Test remaining after account deletion. The remaining entries were unlinked people 6 and 7 in Coffey (household 2); their account and invitation records were already absent, so the exact historical cause could not be reconstructed. With the user's explicit confirmation, these two records were permanently removed using guards for household, ID, name and null user ID. The hosted household screen now shows Joe and Brooklyn. The updated service was deployed alone to the verified recipe document root; its upload archive was moved to recoverable Trash. No database structure or endpoint changes were needed.

Validation passed 31 administrator checks, including the actual add/invite/accept/delete sequence, unlinked accepted-invitation cleanup, rollback and same-name preservation; 59 invitation checks and PHP syntax validation also passed. Live browser verification recorded no warnings or errors.


Expanded administrator deletion: GET /admin/users now returns users with email_verified and a people collection of unlinked table people with household names. POST /admin/people/{id}/delete requires confirm:true, checks the administrator in a transaction, rejects a now-linked person with 409, and deletes invitations and the table person (favorites cascade) without contacting Firebase. Account deletion accepts verified and unverified accounts and self-deletion. There is no last-administrator restriction. The client explicitly warns of loss of administration access before self-deletion. Shared households, recipes and cooking history remain.

Hosted expanded-deletion verification on 2026-10-10 confirmed verified account and unlinked-person rows with enabled delete buttons, both confirmation dialogs and cancellation without deletion. The coordinated backend and web build were deployed to the verified recipe document root, with the upload moved to recoverable Trash. Validation passed 41 administrator checks, 59 invitation checks, 39 Flutter tests, clean Flutter analysis and web/Android debug builds. No live account or person was deleted during this verification.
