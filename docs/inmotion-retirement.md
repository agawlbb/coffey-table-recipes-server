# InMotion CTR content retirement

Task branch `task/retire-inmotion-ctr`, based on main `5dc9dd0`.
The user authorized backup and removal of CTR content on 2026-11-05. Main integration
remains pending explicit approval. This repository is retained as source history.

The complete hosted site and fresh exports of both retained CTR databases are
archived on Samba before any deletion. The backup uses existing share permissions.
The active database is on the VPS and is not exported, deleted or migrated here.

The old shared-host application directory and CTR-only root association files
are removed after backup verification. The retained CTR source databases are also
removed. SMTP mail hosting and unrelated projects on the account remain intact.

All existing GitHub hosting workflows in this repository are disabled immediately
and removed from this branch. The old backend deployment script and its deployment
tests are removed so routine publication cannot recreate the retired content.
Historical files and recovery evidence remain in Git.

Backup and runtime verification details are recorded in the
[VPS repository](https://github.com/agawlbb/coffey-table-recipes-vps/blob/task/retire-inmotion-ctr/INMOTION-RETIREMENT.md).
