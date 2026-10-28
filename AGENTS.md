# Repository workflow

Complete and verify project work, commit it, and push it to the existing GitHub origin. Before declaring completion, verify a clean working tree and matching local/GitHub branch heads. Respect explicit no-push or no-deployment instructions; report any synchronization blocker instead of claiming completion.

- Commit completed changes after appropriate verification, including migrations and documentation where applicable.
- Keep credentials, local configuration, database dumps, dependencies, generated Flutter web builds and uploaded media out of Git.
- This repository contains the PHP server at `C:\xampp\htdocs\CoffeyTableRecipes`. The Flutter app is a separate repository at `C:\Users\mailb\StudioProjects\coffey_table_recipes`. When changing both, commit each repository independently.
- Do not create remote repositories, overwrite unrelated changes, or rewrite history without explicit authorization. Inspect workflow triggers before every push: main normally deploys. A cleanup or synchronization request does not override an explicit no-deployment constraint.

## GitHub, branches and workspaces

- GitHub `agawlbb/coffey-table-recipes-server`, default branch `main`, is the canonical copy of accepted code and history. Laptop, VPS and cloud checkouts are working copies. The default branch is for integration of completed work, never a working branch for a task.
- Prefer the VPS for most development. Development may also take place on the laptop or another authorized environment, including emulator, webview and device testing. Independent tasks use separate, clearly named temporary branches and separate working directories (Git worktrees or clones). Do not share a writable checkout between concurrent tasks. There is no permanent designated chat owner or owner-chat approval requirement.
- Before editing, read applicable instructions and inspect current branches, worktrees, local edits, live GitHub heads and active writers/builds. Preserve unrelated and unpushed work; a clean tree alone does not prove inactivity. Coordinate overlapping changes and shared resources with the tasks involved.
- Before starting new work, fetch from GitHub (`git fetch origin`) and create the temporary task branch from the latest fetched remote default (`origin/<default>`, where `<default>` is this repository's `main`, `master` or `work` branch identified above). Record the task's purpose and base commit; do not branch from a stale local default.
- Before resuming existing work, fetch from GitHub and inspect the intended task branch, its upstream and any divergence from the remote task branch and canonical default. Reconcile updates intentionally without overwriting uncommitted, unpushed or unrelated changes. Preserve local work before switching branches or integrating updates, and resolve conflicts without discarding another task's work. Do not blindly run `git pull` in the current branch or pull another task's branch into it.
- To move the same task between machines, commit and push that task branch, then fetch and continue the intended branch on the other machine after coordinating the handoff and following the resumption checks above. Never overwrite another task's work or use recovery archives as active workspaces.
- Before merging, fetch and integrate the latest canonical default into the task branch, resolve conflicts without discarding others' changes, review the diff and rerun relevant checks against the integrated result. Serialize merges into the default branch and recheck its live head before publishing. Respect existing protections; do not bypass them or rewrite published history.
- Commit and push completed work to the existing GitHub origin, merge accepted work into `main`, and verify the remote commit and a clean task working tree before reporting completion. Report blockers or unsynchronized work explicitly. Inspect push triggers before every push; GitHub publication does not itself authorize builds, releases, deployment or installation.
- Temporary task branches must be removed both locally and remotely once their purpose is fulfilled and their changes are merged into the canonical default branch, or their work is explicitly abandoned by the user or the person responsible for that task. Verify the merge or abandonment decision first; preserve commit history, required recovery evidence and recoverable unique work before deletion. Stale or paused work is not abandoned. Never discard unmerged work without that decision, delete another task's branches, or purge recovery archives as routine cleanup.

## Project history and preservation

- Version 2 meal-planning backend was accepted and merged into `main` on 2026-10-23. Preserve the accepted implementation and historical validation records.

- Recovery archives are historical evidence, not active checkouts. Do not merge them into the default branch, reset their saved edits, or purge them during routine cleanup. Do not revive explicitly rejected work without a user request.
- Keep implementation, documentation and validation in meaningful commits. Preserve useful regression tests, release/deployment configuration and immutable release tags. Published history rewrites require explicit authorization, a verified backup, an exact remote-head check and explicit expected-SHA force-with-lease.
- The user requires no branch protection in either CTR repository. Both main-branch protection rules were removed on 2026-10-22. Do not create or restore branch-protection rules, rulesets, force-push restrictions, or equivalent controls unless the user explicitly requests them. Preserve the history-rewrite safeguards above.

# Hosted development workflow

- When the user requests a release, inspect local and GitHub branches in both repositories. If feature branches exist in addition to main in either repository, ask the user how to handle them before proceeding with the release. Do not assume they should be merged, excluded, or deleted.
- Before every release, commit all project work appropriate for Git and push all local branches and tags from both the Flutter and PHP repositories to their existing GitHub remotes, including work outside the release branch. A release request explicitly authorizes these pushes. Verify both repositories are synchronized before tagging or publishing the release. Preserve the exclusions for credentials, local configuration, generated builds, and personal files; do not force-push or rewrite history without explicit authorization.

- Unless the user specifies otherwise, all changes to database data, database structure, and the backend hosting process occur on the hosted coffeybean.com site.
- Deploying and pushing changes to the hosted backend and database are authorized without additional permission within the requested work, including Git pushes needed for backend deployment.
- Changes to the app itself are developed and tested in the chosen working environment, using coffeybean.com as the backend. Flutter source changes belong in the separate `agawlbb/coffey-table-recipes` repository; its existing laptop checkout is `C:\Users\mailb\StudioProjects\coffey_table_recipes`.
- Update the hosted app only when the user explicitly asks and a new version is tagged. A tag alone does not authorize deployment.
- Outside an instruction to "create a release", obtain explicit permission before deploying the hosted app. A release request authorizes the documented release workflow. App builds on every platform, including debug/emulator packages and GitHub Actions builds/reruns, require an explicit build or release request. Source fixes, cleanup and testing alone do not authorize them.
- Use coffeybean.com as the testing backend. Retain the local PHP source for development. The backend and persistent MySQL database are hosted exclusively; the outdated local recipe database was retired on 2026-10-11.
- Use the Codex built-in browser for work whenever possible. Use Chrome only to investigate Chrome-specific problems identified by the user.
- Deploy only to the verified coffeybean.com document root and a dedicated recipe database; this cPanel account hosts other domains.

- `.github/workflows/deploy-backend.yml` normally runs on every push to main and can migrate and publish to hosting. Before any main push, report that mechanism and confirm deployment is within scope. For a specifically no-deployment documentation/history cleanup, use the documented GitHub `[skip ci]` head-commit instruction only after equivalent local validation; do not silently disable workflows or safeguards. Verify that no deployment was triggered. See `docs/backend-deployment.md`.

## Keep the project simple

This repository deploys only the inMotion PHP backend. Keep its existing layout.
The VPS service is a separate repository, `agawlbb/coffey-table-recipes-vps`.
Avoid overengineering: this is a small personal project. Brief deployment downtime
is acceptable. Prefer copying files and restarting a process. Keep setup separate
from deployment; preserve authentication, credentials and user data. Do not add
release frameworks, compatibility layers or workflows for hypothetical scale.
