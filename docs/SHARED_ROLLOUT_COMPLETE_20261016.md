# Hosted shared-recipe rollout — 2026-10-16

This completion record supersedes the pending-deployment statements in the
preflight record. Following explicit approval, backend commit
`04fc5aae7935c41087caa62537bf9dd690c2cd67` was pushed to its existing feature
branch and deployed. No main merge or hosted Flutter update was performed.

## Preservation and deployment

- Paused the original purge cron and installed maintenance through the rollout helper.
- Verified a protected 371 MB backend/media archive and checked concurrent writers.
- Restored the full database snapshot into empty recovery database
  `negaau5_recipes_recovery_16a14626`; every table's row count/content hash matched.
- Applied migrations 011 and 012, verifying preservation, backfill, and foreign keys.
- A one-use CLI publisher checked archive, manifest, migration bytes, maintenance
  state, and restore proof before atomically replacing/verifying 163 runtime
  files and the deployment receipt.
- Verified hosted Flutter files and media were unchanged.
- Restored the runtime user's original SELECT/INSERT/UPDATE/DELETE grants exactly;
  revoked recovery access and verified connection denial before resuming service.
- Helper final state: `resumed`. Restored the original single `15 3 * * *` PHP
  8.2 purge cron and removed every temporary cron entry. Credentials did not change.

Deployed ZIP SHA-256:
`ebc37e33e5d43a8a01f71360d2880d885f09473b133c3e01de0ea43c803a97c5`.
The public receipt reports the deployed commit above. Protected backups,
recovery data, state, and operational scripts/logs remain available for recovery.
These files and credentials are excluded from Git.

Private storage logs: `shared-pause-20261016.log`,
`shared-writers-20261016.log`, `shared-backup-20261016.log`,
`shared-apply-20261016.log`, and `shared-resume-20261016.log`.
Apply confirms migrated/verified states; resume confirms original permissions
and restored service. Earlier rehearsal and automated results are in the
preflight record.

## Live validation and remaining work

Passed nine public recipe reads across anonymous/invalid/expired-token cases,
eight unauthenticated protected-route checks returning 401, recipe 14's share
page, missing-recipe 404, health, and deployment receipt checks. Public responses
omitted private import/audit fields. External bulk asset rechecks hit HTTP 429
after the first unchanged asset; that HTTP suite is not claimed complete.
Server-side file preservation checks passed.

The existing hosted app passed search, recipe detail, share-link fallback, and
cooking mode checks. A locally built feature app loaded recipe 14 through its
shared link, successfully attached it to the signed-in household, and opened
its 21-step cooking mode. Recipe content was not edited during these checks.

The client feature remains unreleased. Unsigned iOS compilation passed at
`61610fb`; physical-device alarms, widget signing/provisioning, and signed
distribution remain outstanding. No TestFlight upload occurred.

## Main merge for client 1.6.0 release

At the user's explicit merge/delete/release request, `feature/shared-recipes-public-reads` was merged into main with merge commit `e859ca7`. Local fixture checks passed: 18 shared recipe checks, 97 canonical variation relationship checks and six migration checksum checks. The workflow file's normalized content matched its committed blob; refreshing its index cleared a stat-only modification without changing source. The existing verified hosted migration/restore record above remains authoritative; no repeat of the one-time migration job is requested. The corresponding client release will record deployment and signed distribution outcomes separately.

The first main deployment run `38994182484` stopped before publishing: the parent-category fixture still used the pre-sharing schema and lacked `recipe_households`. Added the canonical recipe and active household membership to its isolated in-memory setup, retaining the existing disabled-image permission assertion. All 17 deployment PHP fixture scripts then passed locally with normal file/temp access, including all 49 parent-category checks; no live database or email was accessed. Initial sandbox-only attempts failed on file access and are not counted as validation passes. This is a fixture correction, without production logic or database changes.
