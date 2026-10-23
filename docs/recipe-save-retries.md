# Recipe save receipts

Recipe creates and edits accept an optional `client_save_id`, generated once for
each durable outbox entry. Migration 017 adds receipts scoped to household, user
and identifier. A SHA-256 hash binds the identifier to the original recipe path
and canonical request body. Reusing it with different content returns 409.

The existing relationship lock serializes concurrent saves. The recipe mutation
and immutable original response commit in the same transaction. Retrying returns
that response, including the original revision and child identifiers, without
creating another recipe or applying the edit again. Membership and current
recipe visibility are checked on replay. Deleted/detached recipes cannot be
resurrected through an old receipt. Existing clients without identifiers retain
their previous behavior. Import duplicate checks remain independent.

Receipts deliberately have no short expiration: an offline device may return
months later. They are private database records, excluded from household exports
and snapshots. Account/household deletion cascades their corresponding receipts.

The dedicated CLI migration verifies the exact hosted recipe database, backs it
up, applies only additive migration 017, verifies the table and records its
checksum. It uses the existing reviewed temporary cron/permission restoration
pattern. Runtime deployment follows successful migration. Main source is kept
compatible; hosting uses the planner branch to preserve its existing routes.

Validation: receipt SQLite fixtures cover rollback, immutable replay, scope,
canonical hashing, changed-body/path rejection and malformed identifiers. The
MySQL editor fixture covers actual create/edit replay, recipe counts, newer
edits, authorization and validation rollback. Deployment runs this fixture in
an isolated MySQL service, never the hosted data. Local Python suite: 121 mocked
tests passed, including migration cleanup and privilege restoration.

Hosted validation on 2026-10-23: Actions run 39998462691 succeeded with 58
recipe-editor checks in isolated MariaDB, the separate MySQL planner fixture,
121 mocked Python tests and snapshot checks. Migration 017 backed up the dedicated
recipe database, retained its recipe count, verified the receipt schema and
restored original database privileges. Runtime receipt matches source
`080f4f54334f3e093190749ac70d0fd14ff38afb`. Planner routes remain protected and
available. No hosted Flutter publication was performed.
