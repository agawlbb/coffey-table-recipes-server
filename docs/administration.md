# Account administration

Application administration is separate from household membership roles. The
backend verifies the Firebase ID token and uses its stable UID to find the local
user record. Administration requires that record's account_role to be administrator.
An email address, Google OAuth client ID, or unverified client claim cannot grant it.

Existing assigned administrators retain access, including when their email changes.
New installations have no automatic administrator. A trusted database operator must
assign the role to the intended verified Firebase UID using a parameterized database
update after that account has signed in. Keep the deployment's actual UID private.

Migration 008 adds the role column. The migration runner recognizes the exact
original installed checksum as a legacy case after removal of its personal email
grant; it does not rerun the migration or change existing roles. Other checksum
mismatches still fail.

The API checks administration on every privileged request; hiding a menu is not
authorization. Tests cover denied access, identity continuity, deletion rollback,
self-deletion prevention, shared-content preservation, and deleted-account checks.

## Household AI images

Migration 009 adds `households.ai_images_enabled`, default false for both existing
and new households. Application administrators can list registered households and
member counts with GET `/admin/households`, or in the `/admin/users` inventory.
POST `/admin/households/{id}/ai-images` accepts a strict JSON boolean `enabled`.
Household owners and household admins cannot change this application-wide control.

Recipe image generation checks the authenticated user's membership and the current
household permission before contacting AI. Category artwork checks the same permission
before creating missing shared artwork. Existing artwork remains available without
another AI call. Revocation blocks subsequent requests; an already-running request
may finish. This setting does not impose a spending cap or change recipe importing.

The administrator panel lists household switches. The recipe editor disables AI
generation with an explanation while retaining camera and image upload controls.
Server enforcement remains authoritative for older clients and direct API requests.

Validation: 62 administrator checks cover existing/new defaults, ordinary-member
access, administrator-only switches, invalid input, cross-household denial and
revocation. Recipe editing tests verify disabled category generation makes no AI call
and previously generated artwork can be reused after revocation.

Hosted validation on 2026-10-12: migration 009 and backend commit `b895c86`
deployed successfully in GitHub Actions run 38323429701. All existing households
were verified disabled. The complete pre-migration backup returned HTTP 403 to
public requests, original database privileges were restored and verified, and the
temporary cron job was removed. Hosted health passed; anonymous household inventory,
permission changes and recipe-image generation returned HTTP 401. Backend deployment
and migration orchestration passed 25 Python tests, with 62 administrator, 50 recipe
editing, 55 schema, 51 household/authentication, 77 AI-import and six migration-checksum
checks passing locally. Image calls in tests were simulated. No real AI image was
generated. The Flutter administration UI is committed separately and awaits an
explicitly authorized tagged app deployment.
