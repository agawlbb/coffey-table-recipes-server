# Household invitations

Apply `database/migrations/007_household_invitations.sql` with a schema administrator after backing up the database. It adds normalized email identity claims to users and stores invitations separately from memberships. Sending or pending invitations grant no access. Acceptance creates a member membership and links the existing household person, preserving favorites and person history. Declining leaves the person unlinked.

Invitations expire after 14 days. Only household owners/admins may invite. Existing Firebase accounts that already have a household are rejected. Acceptance requires the authenticated account's verified email to match the invitation. Household creation and acceptance lock the same account row to enforce the current single-household rule without removing the database's multiple-membership capability.

## Server configuration

Keep `config/smtp.local.json` and `config/firebase-admin.local.json` outside Git and inaccessible over HTTP. The SMTP file contains host, port, encryption (`ssl` or `tls`), username, password, from_email and from_name. Use the cPanel mailbox's authenticated SMTP service with certificate verification enabled. The sender mailbox is unmonitored; it may still receive delivery failures.

The Firebase JSON key must belong to `coffey-table-recipes`. Use a dedicated service account with a custom role containing only `firebaseauth.users.get`. Lookup uses Google's service-account OAuth flow and Identity Toolkit accounts:lookup endpoint. Never use a broad Firebase admin key. Set `app_url` to the deployed web app, and upload the Composer production dependencies including PHPMailer. Configuration paths are defined in `config/example.php` and can be overridden in `config/local.php`.

## API

- `POST /households/{householdId}/people/{personId}/invitations` with `{ "email": "person@example.com" }` sends the invitation and returns its ID/status.
- `GET /me` includes `invitations` and `invitation_verification_required`. Unverified emails receive no household invitation details.
- `POST /invitations/{id}/respond` with `{ "decision": "accept" }` or `decline` records the response. Accepting cancels other pending invitations for that email under the single-household rule.

Email send failures remain recorded as failed and allow retry. Duplicate active invitations are blocked by application checks and database uniqueness. The per-inviter limit is 20 attempts per hour.

## Validation

`php tests/invitations.php` exercises the migration and service with a disposable database and fake Firebase/mail callbacks. It covers account eligibility, owner/admin access, email matching/verification, duplicate invites, sending failures, expiry, acceptance preserving the original person, declining, and household creation after acceptance. Real Firebase lookup and SMTP authentication must also be verified during hosted deployment; these tests do not deliver email.

Expanded validation on 2026-10-10 passed 57 checks, including recipe access denied before acceptance and allowed afterward, disabled Firebase/local accounts, revoked inviter permissions, disabled recipients, and the 20-attempt limit including failed delivery attempts. Hosted unauthenticated GET `/me` and POST invitation/send-response routes returned 401 without creating invitations. Hosted SMTP authentication and Firebase lookup passed during deployment; a real recipient delivery test remains pending.
