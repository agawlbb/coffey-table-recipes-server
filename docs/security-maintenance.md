# Workflow and key-installation security

Reviewed October 13, 2026. All GitHub Actions use exact executable commits,
resolved from the existing major-version tags and checked against upstream:

- actions/checkout v4: `11d5960a326750d5838078e36cf38b85af677262`
- actions/upload-artifact v4: `ea165f8d65b6e75b540449e92b4886f43607fa02`
- shivammathur/setup-php v2: `eb7c497e18156a6bbabfef1d3a82760b9eda3962`

Review upstream changes before updating these hashes. A tag object is not an
executable commit; annotated tags must be dereferenced before pinning.

The AI-key installer now requires a direct public HTTP 403 before any key write.
Public checks stream only headers and do not follow redirects or read secret
response bodies. Existing configuration is backed up and privately verified in
the account home, outside the coffeybean.com document root, before overwrite.
Existing private extractor settings survive key replacement.

Any upload/readback/postcheck failure attempts to move the possibly installed
key outside the website tree and verifies the private destination and absence
of the web-tree file. A previous key is restored only after another protection
check, with private readback and a final protection check. A failed restoration
is quarantined again. Rollback failures produce an explicit manual-recovery
error without secret values. Recovery and failed-install files use unique
`recipe-ai-recovery-*.json` and `recipe-ai-failed-*.json` names in `/home/ACCOUNT`;
they are private recovery material, never deployable files or Git content.
Review/remove them privately after recovery or a successful replacement.

The live key URL returned HTTP 403 on October 13, 2026. No key was provisioned,
rotated, or uploaded during this maintenance.

The deployment workflow reads LangPHP/php_get_vhost_versions through existing
cPanel authentication, selects only coffeybean.com, and prints only its resolved
PHP handler. This does not change PHP settings or expose a phpinfo endpoint.
CI remains on PHP 8.2 pending a tested hosting upgrade. PHP 8.2 security support
ends December 31, 2026. CLI maintenance jobs explicitly use ea-php82 and must
also be reviewed when changing PHP. Source: https://www.php.net/supported-versions.php

Validation: 48 offline Python deployment/configuration tests passed, including
precheck rejection, upload timeout, rollback, private prior-key recovery,
restoration, and protection loss during restoration. No database data changed
in these local tests.

Hosted validation: backend commit `60b8a59ba5fe98abbd75a619956b63c3f6a4ddd5`
was deployed and verified in successful Linux workflow run `38394933074`:
https://github.com/agawlbb/coffey-table-recipes-server/actions/runs/38394933074
The workflow passed PHP syntax, Composer installation, the backend behavior
suite, all 48 Python tests, cPanel TLS, hosted health/CORS/internal-path checks,
deployment receipt verification, and daily cleanup schedule verification.

The authenticated cPanel query reported **ea-php81 for coffeybean.com**.
PHP 8.1 upstream support ended December 31, 2025:
https://www.php.net/eol.php
This is higher priority than the original CI-only PHP 8.2 maintenance note.
It does not establish that the site has been compromised, nor does the API
report the precise patch version or the provider's possible backported fixes.
The live PHP setting was not changed in this maintenance.

Upgrade plan (separate live-runtime change):

1. Confirm which supported handlers/extensions hosting makes available; prefer
   PHP 8.4 if available (security support through December 31, 2028).
2. Check locked Composer requirements and run the PHP syntax/behavior suite on
   the candidate version. Match CI to that version after compatibility passes.
3. Record the current handler and any application-directory overrides, and
   preserve a rollback path. Change only coffeybean.com, not other account sites.
4. Verify hosted health, authenticated read-only recipe flows, public shares,
   import preprocessing, and photo access immediately after the switch. Roll
   back if checks fail. Do not create production test recipes or send mail.
5. Update the explicit PHP binary paths for recipe maintenance jobs after
   verifying CLI compatibility, then record the results in Git.
