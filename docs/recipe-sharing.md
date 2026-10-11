# Public recipe links

Authenticated `GET /api/v1/households/{household}/recipes/{recipe}/share` checks membership and recipe existence and returns a signed URL under the configured `app_url` root: `share.php?token=…`.

Set `recipe_share_key` in private `config/local.php` to a cryptographically random value of at least 32 characters (for example the output of `php -r "echo bin2hex(random_bytes(32));"`). Never commit it. Sharing returns 503 until configured. Key rotation invalidates all existing links. No schema change is required.

The public handler verifies the HMAC before reading data, then rechecks the sender's membership and recipe existence. The capability grants access to one recipe. Anyone with a link can view its current contents without authentication; links have no expiry. Other recipe titles appear without links. Import bookkeeping, raw import text, household member data and edit options are not rendered. Pages disallow indexing, use no-store and no-referrer headers, escape all recipe text and allow only HTTP(S) images.

Deploy the API route, config default, share.php and both RecipeShare classes together. No hosted deployment or private key creation was performed during implementation.

Validation: `php tests/recipe_sharing.php` tests signed link round trips, tampering, key rotation, missing configuration, renderer escaping, selected recipe sections and private-field omission. It does not change database data.

Implementation verification: all 22 checks passed, along with lint of the public handler, API router and both new classes. Built-in browser preview timed out against localhost; hosted integration and visual verification remain pending deployment.
