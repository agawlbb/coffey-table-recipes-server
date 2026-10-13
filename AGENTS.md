# Repository workflow

The user requests that all future project changes be committed to Git.

- Commit completed changes after appropriate verification, including migrations and documentation where applicable.
- Keep credentials, local configuration, database dumps, dependencies, generated Flutter web builds and uploaded media out of Git.
- This repository contains the PHP server. The Flutter app is a separate repository at `C:\Users\developer\StudioProjects\coffey_table_recipes`. When changing both, commit each repository independently.
- Do not push or create remote repositories unless the user requests it. Do not overwrite unrelated changes or rewrite history without explicit authorization.

# Hosted development workflow

- Unless the user specifies otherwise, all changes to database data, database structure, and the backend hosting process occur on the hosted coffeybean.com site.
- Deploying and pushing changes to the hosted backend and database are authorized without additional permission within the requested work, including Git pushes needed for backend deployment.
- Changes to the app itself are developed and tested locally, using coffeybean.com as the backend. Flutter source changes belong in `C:\Users\developer\StudioProjects\coffey_table_recipes`.
- Update the hosted app only when the user explicitly asks and a new version is tagged. A tag alone does not authorize deployment.
- Outside an instruction to "create a release", obtain explicit permission before pushing or deploying the hosted app. An instruction to "create a release" authorizes hosted app deployment; macOS GitHub Actions builds still require separate approval every time.
- Use coffeybean.com as the testing backend. Retain the local PHP source for development. The backend and persistent MySQL database are hosted exclusively; the outdated local recipe database was retired on 2026-10-11.
- Use the Codex built-in browser for work whenever possible. Use Chrome only to investigate Chrome-specific problems identified by the user.
- Deploy only to the verified coffeybean.com document root and a dedicated recipe database; this cPanel account hosts other domains.

- Backend deployment may run automatically after pushes to main. macOS runner builds still require explicit approval in the Flutter repository.
