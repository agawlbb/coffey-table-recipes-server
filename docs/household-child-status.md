# Household child-mode status

The authenticated household people response includes a boolean `child_mode`
for linked accounts and false for people without accounts. The same data enters
household snapshots and their version hash, keeping refreshed offline views
consistent. Household membership is checked before reading people or policy.

The existing ChildMode authority supplies status from protected storage. Firebase
UIDs are used internally and removed before serialization; PIN/hash/throttle/reset
metadata is never exposed. A pending reset continues to report enabled until the
account's qualifying next login applies the reset. No schema change is required.

Validation: PHP syntax checks for index.php and HouseholdService.php,
tests/child_mode.php and tests/household_child_status.php passed. The latter also
runs the existing snapshot fixture and verifies linked child/adult/unlinked rows,
nonmember denial, boolean-only metadata, pending resets and snapshot invalidation.
The new fixture is included in deploy-backend.yml. Main pushes deploy the backend;
this scoped deployment supports the requested household child-account badge.
