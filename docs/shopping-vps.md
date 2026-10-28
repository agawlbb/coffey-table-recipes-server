# Shopping moved to the VPS

Branch `migration-take-2` starts at server `origin/main` commit
`7548c5da474c5708a3a3a76b6fe448bf6923da3f`. It removes shopping transaction
handlers, shopping snapshot data and their unused services/setup jobs. All other
PHP source locations, Apache routing and deployment remain as they were on main.
Old shopping transaction endpoints return 410 unconditionally.

HA sign-in and connection management remain here. Their existing interface uses
the SQL credential table already used by the VPS; migration 019 and the original
credential import have already been applied on the hosted database. They are
not repeated during deployment. Revocation is immediately visible to the VPS.

VPS source is maintained independently at
https://github.com/agawlbb/coffey-table-recipes-vps . This server repository does
not deploy it or read any VPS files or credential paths. Deployment retains its
existing CPANEL_USER/CPANEL_API_TOKEN environment-variable interface.

The original `migration/vps-backend` branch is preserved and has not been changed.
Do not merge that older branch as part of this replacement.

Validation: all 28 remaining PHP fixture commands in the existing workflow passed;
all 122 retained Python tooling tests passed, including HTTP retirement, packaging
and deployment-failure fixtures. Tests used disposable/mocked services, not hosted
shopping data. Strict Composer and PHP syntax checks passed. No deployment was
performed by this branch preparation.
