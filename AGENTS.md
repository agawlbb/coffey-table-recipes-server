# Repository workflow

The user requests that all future project changes be committed to Git.

- Commit completed changes after appropriate verification, including migrations and documentation where applicable.
- Keep credentials, local configuration, database dumps, dependencies, generated Flutter web builds and uploaded media out of Git.
- This repository contains the PHP server. The Flutter app is a separate repository at `C:\Users\developer\StudioProjects\coffey_table_recipes`. When changing both, commit each repository independently.
- Do not push or create remote repositories unless the user requests it. Do not overwrite unrelated changes or rewrite existing history.

# Hosted development workflow

- Always use the Codex built-in browser for browser work, rather than Chrome.

- The user selected coffeybean.com as the testing backend host. Keep the local PHP project and local database for future development.
- Always use coffeybean.com as the testing backend for future work; retain the local database/API for future development.
- Deploy only to the verified coffeybean.com document root and a dedicated recipe database; this cPanel account hosts other domains.
