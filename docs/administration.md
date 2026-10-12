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
