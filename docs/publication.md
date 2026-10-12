# Open-source publication review

Prepared on 2026-10-12 with explicit authorization for private recovery backups,
history sanitization, force-pushing cleaned references and conditional publication.

The project uses GNU AGPL-3.0-only; LICENSE and NOTICE.md explain source, hosted
service, artwork and dependency notices. The public recipe page offers a source
link, and runtime deployment includes the license and notice.

Administration is tied to the existing database role and verified Firebase UID,
not an automatic personal-email grant. Existing administrators retain their role.
Migration 008's personal grant is removed for new installs; the migration runner
accepts only the exact known original/cleaned hashes for already-applied copies,
including LF and CRLF, without rerunning SQL or changing existing roles.

Hosting paths derive the account component from the existing CPANEL_USER secret.
Server configuration, SMTP credentials, Firebase service-account files, AI keys,
uploaded media and database dumps remain excluded. Personal information is
sanitized throughout published history. Original Git bundles and Actions archives
remain in a private local recovery area outside both repositories.

Existing public recipe sharing and AI access are unchanged. Deployment remains
automatic after trusted pushes to main; deployment is not triggered by public
pull requests. macOS builds belong to the Flutter repository and always require
explicit approval. No hosted Flutter deployment is authorized by publication.

Validation: 50 administrator and 51 authentication/household checks passed using
disposable local databases; the temporary local database engine was stopped.
Also passed: 31 recipe-sharing checks, six strict migration-checksum regression
checks, 17 deployment/key-configuration tests, PHP syntax checks and Composer
validation. Key-configuration tests use fake transports and do not call AI models.

Before publication, all cleaned refs must pass the final credential/privacy scan,
old Actions records must be retired after verified private backups, and the hosted
backend deployment must succeed. Replace old clones after the rewrite; never merge
or force-push the original personal history back into the public repositories.

## Clean-history verification

All 192 cleaned app/backend commits and 799 historical text blobs passed the
privacy-pattern scan and Gitleaks 8.30.1 with zero findings. Every published
branch and tag was atomically replaced using leases against the recorded originals;
remote references matched the cleaned local history.

GitHub still serves personal details through original detached commit IDs.
Visibility remains private pending a decision on fresh public repositories,
GitHub cache removal, or acceptance of that residual exposure. Private recovery
bundles and 53 workflow log archives plus 28 artifact archives are verified.

Backend deployment remains automatic. Checksum and public source-link behavior
are included in CI validation. AI access and public recipe sharing are unchanged.

## Remaining publication and deployment gates

Old Actions runs and artifacts remain private, not deleted. Automatic approval
review rejected their permanent deletion because the user authorized Git history
rewriting but had not separately authorized deletion of those build records.
Their ZIP backups, including logs from three earlier rerun attempts, are verified.

Deployment run 38264678428 was blocked before any step ran: GitHub reported recent
payment failure or an insufficient spending limit. The existing hosted backend
was not modified. A direct hosting session requires a fresh login. Automatic main
deployment remains enabled, but billing access must be restored before it can run.
Both repositories remain private while cached original personal-data commits are
still accessible. Publishing fresh sanitized repositories avoids those caches.

## Fresh repository publication

On 2026-10-12 the user chose fresh public repositories containing only sanitized
history, retaining the original repositories and Actions records privately.
The public source destinations are agawlbb/coffey-table-recipes and
agawlbb/coffey-table-recipes-server. Only branches and tags are copied; private
recovery refs, original cached commits, and old build records are excluded.

Local origin will point to the new public repository, with private-history retaining
the old remote for recovery. Existing clones should use the new public URLs.
Firebase client build configuration may be transferred as an encrypted GitHub
secret without printing its contents. Hosting secrets cannot be read back from
GitHub; they must be supplied to the new repositories before hosting deployment.
Backend checks still run without them; automatic deployment activates when both
CPANEL_USER and CPANEL_API_TOKEN are configured. No AI access limits change.
All iOS builds remain manual and require explicit approval. Publication performs
no macOS build and no hosted Flutter update.

## AI access decision

The owner requested removal of the 20-import-requests-per-hour quota on 2026-10-12.
This is a separate authorized behavior change from repository publication.
Authentication and household authorization remain unchanged; SECURITY.md explains
why public source does not expose the API key or grant anonymous backend access.
Registration can occur through the app or Firebase APIs, so website visitation
is not an enforceable boundary. Backend validation exercised 25 successive imports
with an exhausted legacy counter and a fake upstream transport: all 77 recipe AI
checks passed without paid API calls. The hosted change awaits deployment credentials.
