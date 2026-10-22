# Repository workflow

Complete and verify project work, commit it, and push it to the existing GitHub origin. Before declaring completion, verify a clean working tree and matching local/GitHub branch heads. Respect explicit no-push or no-deployment instructions; report any synchronization blocker instead of claiming completion.

- Commit completed changes after appropriate verification, including migrations and documentation where applicable.
- Keep credentials, local configuration, database dumps, dependencies, generated Flutter web builds and uploaded media out of Git.
- This repository contains the PHP server at `C:\xampp\htdocs\CoffeyTableRecipes`. The Flutter app is a separate repository at `C:\Users\mailb\StudioProjects\coffey_table_recipes`. When changing both, commit each repository independently.
- Do not create remote repositories, overwrite unrelated changes, or rewrite history without explicit authorization. Inspect workflow triggers before every push: main normally deploys. A cleanup or synchronization request does not override an explicit no-deployment constraint.

## Ownership and offline work

- While the laptop is connected, the designated owner is the existing **Reconcile project with GitHub** chat (`01a15fd0-827e-77c3-a0a3-737af531ea3f`). It coordinates both CTR repositories. Work in the canonical folders above, rather than hidden clones or competing active copies.
- Other chats must obtain a read-only handoff before writing. Check current branches, worktrees, local edits, GitHub heads and active tasks/processes; an earlier clean report is not proof of current state.
- Only explicitly requested offline work uses a named branch in the same repository, normally `codex/offline-<topic>`. Record the branch and base commit in the handoff. Never put private runtime configuration or credentials on the branch.
- On the laptop's return, the owner reviews against current main, preserves unique work, merges only accepted changes, tests, commits and synchronizes with GitHub. Remove temporary branches only after accepted work is preserved and synchronization verified; archive rejected work recoverably before retiring its branch.
- Recovery archives are historical evidence, not active checkouts. Never merge them into main, reset their saved edits, or purge them during routine cleanup. Do not revive rejected or obsolete diagnostic branches.
- Keep related implementation, documentation and validation in meaningful commits. Preserve useful regression tests, current release/deployment configuration and immutable release tags. Published history rewrites require a verified backup, explicit authorization, an exact remote-head check and explicit expected-SHA force-with-lease.
- The owner explicitly requires no branch protection in either CTR repository. Both main-branch protection rules were removed on 2026-10-22. Do not create or restore branch-protection rules, rulesets, force-push restrictions, or equivalent controls unless the owner explicitly requests them. History rewrites still require the authorization, backup and exact-SHA lease checks above.

# Hosted development workflow

- When the user requests a release, inspect local and GitHub branches in both repositories. If feature branches exist in addition to main in either repository, ask the user how to handle them before proceeding with the release. Do not assume they should be merged, excluded, or deleted.
- Before every release, commit all project work appropriate for Git and push all local branches and tags from both the Flutter and PHP repositories to their existing GitHub remotes, including work outside the release branch. A release request explicitly authorizes these pushes. Verify both repositories are synchronized before tagging or publishing the release. Preserve the exclusions for credentials, local configuration, generated builds, and personal files; do not force-push or rewrite history without explicit authorization.

- Unless the user specifies otherwise, all changes to database data, database structure, and the backend hosting process occur on the hosted coffeybean.com site.
- Deploying and pushing changes to the hosted backend and database are authorized without additional permission within the requested work, including Git pushes needed for backend deployment.
- Changes to the app itself are developed and tested locally, using coffeybean.com as the backend. Flutter source changes belong in `C:\Users\mailb\StudioProjects\coffey_table_recipes`.
- Update the hosted app only when the user explicitly asks and a new version is tagged. A tag alone does not authorize deployment.
- Outside an instruction to "create a release", obtain explicit permission before deploying the hosted app. A release request authorizes the documented release workflow. App builds on every platform, including debug/emulator packages and GitHub Actions builds/reruns, require an explicit build or release request. Source fixes, cleanup and testing alone do not authorize them.
- Use coffeybean.com as the testing backend. Retain the local PHP source for development. The backend and persistent MySQL database are hosted exclusively; the outdated local recipe database was retired on 2026-10-11.
- Use the Codex built-in browser for work whenever possible. Use Chrome only to investigate Chrome-specific problems identified by the user.
- Deploy only to the verified coffeybean.com document root and a dedicated recipe database; this cPanel account hosts other domains.

- `.github/workflows/deploy-backend.yml` normally runs on every push to main and can migrate and publish to hosting. Before any main push, report that mechanism and confirm deployment is within scope. For a specifically no-deployment documentation/history cleanup, use the documented GitHub `[skip ci]` head-commit instruction only after equivalent local validation; do not silently disable workflows or safeguards. Verify that no deployment was triggered. See `docs/backend-deployment.md`.
