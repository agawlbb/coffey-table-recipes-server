# Repository workflow

The user requests that all future project changes be committed to Git.

- Commit completed changes after appropriate verification, including migrations and documentation where applicable.
- Keep credentials, local configuration, database dumps, dependencies, generated Flutter web builds and uploaded media out of Git.
- This repository contains the PHP server. The Flutter app is a separate repository at `C:\Users\developer\StudioProjects\coffey_table_recipes`. When changing both, commit each repository independently.
- Do not push or create remote repositories unless the user requests it. Do not overwrite unrelated changes or rewrite history without explicit authorization.

# Hosted development workflow

- When the user requests a release, inspect local and GitHub branches in both repositories. If feature branches exist in addition to main in either repository, ask the user how to handle them before proceeding with the release. Do not assume they should be merged, excluded, or deleted.
- Before every release, commit all project work appropriate for Git and push all local branches and tags from both the Flutter and PHP repositories to their existing GitHub remotes, including work outside the release branch. A release request explicitly authorizes these pushes. Verify both repositories are synchronized before tagging or publishing the release. Preserve the exclusions for credentials, local configuration, generated builds, and personal files; do not force-push or rewrite history without explicit authorization.

- Unless the user specifies otherwise, all changes to database data, database structure, and the backend hosting process occur on the hosted coffeybean.com site.
- Deploying and pushing changes to the hosted backend and database are authorized without additional permission within the requested work, including Git pushes needed for backend deployment.
- Changes to the app itself are developed and tested locally, using coffeybean.com as the backend. Flutter source changes belong in `C:\Users\developer\StudioProjects\coffey_table_recipes`.
- Update the hosted app only when the user explicitly asks and a new version is tagged. A tag alone does not authorize deployment.
- Outside an instruction to "create a release", obtain explicit permission before pushing or deploying the hosted app. An instruction to "create a release" authorizes hosted app deployment. iOS builds require no separate approval.
- Use coffeybean.com as the testing backend. Retain the local PHP source for development. The backend and persistent MySQL database are hosted exclusively; the outdated local recipe database was retired on 2026-10-11.
- Use the Codex built-in browser for work whenever possible. Use Chrome only to investigate Chrome-specific problems identified by the user.
- Deploy only to the verified coffeybean.com document root and a dedicated recipe database; this cPanel account hosts other domains.

- Backend deployment may run automatically after pushes to main. iOS builds require no separate explicit approval within requested work, including macOS GitHub Actions runs, reruns, and pushes or tags that trigger iOS builds. Other macOS runner builds still require explicit approval because they cost money.
