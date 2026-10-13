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
ends December 31, 2026; if hosting uses it, test and schedule an upgrade before
that date. CLI maintenance jobs explicitly use ea-php82 and must also be
reviewed when changing PHP. Source: https://www.php.net/supported-versions.php

Validation: 48 offline Python deployment/configuration tests passed, including
precheck rejection, upload timeout, rollback, private prior-key recovery,
restoration, and protection loss during restoration. No database data changed
in these local tests.
