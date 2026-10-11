# Public recipe links

Authenticated `GET /api/v1/households/{household}/recipes/{recipe}/share` checks membership and recipe existence and returns a signed URL under the configured `app_url` root: `share.php?token=…`.

Set `recipe_share_key` in private `config/local.php` to a cryptographically random value of at least 32 characters (for example the output of `php -r "echo bin2hex(random_bytes(32));"`). Never commit it. Sharing returns 503 until configured. Key rotation invalidates all existing links. No schema change is required.

The public handler verifies the HMAC before reading data, then rechecks the sender's membership and recipe existence. The capability grants access to one recipe. Anyone with a link can view its current contents without authentication; links have no expiry. Other recipe titles appear without links. Import bookkeeping, raw import text, household member data and edit options are not rendered. Pages disallow indexing, use no-store and no-referrer headers, escape all recipe text and allow only HTTP(S) images.

Deploy the API route, config default, share.php and both RecipeShare classes together.

Validation: `php tests/recipe_sharing.php` tests signed link round trips, tampering, key rotation, missing configuration, renderer escaping, selected recipe sections and private-field omission. It does not change database data.

Implementation verification: all 22 checks passed, along with lint of the public handler, API router and both new classes. Built-in browser preview timed out against localhost; hosted integration and visual verification remain pending deployment.

## Hosted deployment — 2026-10-11

Deployed sharing from commit `1a395f7` to the verified `/home/hosting_account/coffeybean.com/CoffeyTableRecipes` directory after explicit user authorization. Added the share route to the existing hosted router, preserving its other routes. Installed `share.php`, both new classes, and the configuration default; all four deployed file hashes match committed sources. Generated a private random signing key in hosted `config/local.php`, with permissions 0600. No database data or schema changes were needed. The Flutter hosted app was not redeployed.

Previous files and private configuration are backed up under protected `storage/before-recipe-sharing-20261011-123837`, with private backups set to 0600. A guarded one-use installer performed the deployment and removed itself; its former URL returns 404.

The existing **Mint Chip Ice Cream** recipe returned 200 through its signed public link. Built-in-browser inspection confirmed the photograph, cream background, green branding, ingredients, method, action chips and source. Hosted checks also confirmed invalid/missing links return 404, the authenticated sharing API rejects anonymous requests with 401, health returns 200, configuration and backup URLs return 403, and public recipe responses include no-store, no-referrer and noindex headers. Example-link details are retained in the ignored local deployment result; no signing keys are recorded in Git.
