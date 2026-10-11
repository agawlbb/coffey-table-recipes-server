# Public recipe links

## Recipe heading cleanup - 2026-10-11

The recipe title is now the first and largest heading, with compact Coffey Table Recipes branding beneath it. Section headings use green text and a subtle divider. Removed the visible language/measurement metadata and all category tags. The branding JPEG uses `mix-blend-mode: multiply` to blend its embedded white surround into the cream page without modifying the shared email image.

Deployed only `src/RecipeSharePage.php`; its previous version is protected at `storage/before-sharing-layout-20261011-130528.php`. The deployed hash matched the local renderer, 31 backend checks and PHP lint passed, and built-in-browser visual inspection confirmed the title hierarchy and absence of white branding surround on Mint Chip Ice Cream. Live recipe and missing-recipe responses returned 200 and 404 respectively; the temporary installer removed itself and returned 404. No app build or database changes were needed.

Recipe pages use `share.php?id={database recipe ID}`. No signing key, token, expiry, household ID, or sender identity is needed. Anyone can read an existing recipe by its ID. The page is read-only and does not expose raw imports, household members, edit options, or step actions. The authenticated `/households/{household}/recipes/{recipe}/share` route remains compatible and returns the same ID-based URL.

Missing, malformed, or nonexistent IDs return HTTP 404 with a useful branded error in the app's cream, green, and brown colors. Operational failures return 500 with a retry message. The page uses the existing `assets/email/coffey_table.jpg` branding image. Recipe photo backgrounds are transparent, matching the page background; tags remain visible but action chips are omitted.

## Simplified sharing deployment - 2026-10-11

Deployed the public reader, renderer, handler, configuration default and isolated sharing route update to the verified coffeybean.com recipe directory. Existing hosted router features were preserved. Backups are protected under `storage/before-simple-sharing-20261011-125154`; the guarded temporary installer removed itself (verified 404). No database changes or Flutter hosted-app deployment were made. The earlier private signing key is unused.

Validation: 26 backend checks and PHP lint passed. Four deployed file hashes matched local sources. Live Mint Chip Ice Cream (`share.php?id=9`) returned 200 and was visually checked in the built-in browser: branding loads, photo surround matches the page, and action chips are absent. Missing, malformed and array IDs returned branded 404 pages. API health returned 200; the authenticated sharing route returned 401 anonymously; the branding image returned 200; private configuration returned 403. The app's 10 targeted tests and focused analysis passed.

## Previous signed-link deployment (superseded)

## Hosted deployment — 2026-10-11

Deployed sharing from commit `1a395f7` to the verified `/home/hosting_account/coffeybean.com/CoffeyTableRecipes` directory after explicit user authorization. Added the share route to the existing hosted router, preserving its other routes. Installed `share.php`, both new classes, and the configuration default; all four deployed file hashes match committed sources. Generated a private random signing key in hosted `config/local.php`, with permissions 0600. No database data or schema changes were needed. The Flutter hosted app was not redeployed.

Previous files and private configuration are backed up under protected `storage/before-recipe-sharing-20261011-123837`, with private backups set to 0600. A guarded one-use installer performed the deployment and removed itself; its former URL returns 404.

The existing **Mint Chip Ice Cream** recipe returned 200 through its signed public link. Built-in-browser inspection confirmed the photograph, cream background, green branding, ingredients, method, action chips and source. Hosted checks also confirmed invalid/missing links return 404, the authenticated sharing API rejects anonymous requests with 401, health returns 200, configuration and backup URLs return 403, and public recipe responses include no-store, no-referrer and noindex headers. Example-link details are retained in the ignored local deployment result; no signing keys are recorded in Git.
