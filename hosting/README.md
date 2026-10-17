# Domain-root Android association

Publish `assetlinks.json` to the verified domain document root:
`/home/negaau5/coffeybean.com/.well-known/assetlinks.json`.
This is outside the PHP application's deployment directory. Merge with any
existing entries for other apps; never replace domain-wide data blindly.

The public SHA-256 certificate was verified with apksigner against the delivered
`CoffeyTableRecipes-1.6.0-build20.apk`. It is a certificate fingerprint, not a key.
The application manifest restricts handled links to `/CoffeyTableRecipes/share.php`.

On 2026-10-16 the association URL returned 404 after release: the Flutter web
deployment publishes only app assets and had not performed this separate step.
The verified `.well-known` directory contained only `acme-challenge`; no existing
association entries were overwritten. Published this file through the existing
cPanel session. Public HTTPS verification passed HTTP 200/application-json,
with no redirects and a matching Android package/certificate.

Normal Android domain re-verification now reports `coffeybean.com: verified`
and lists this package as VERIFIED owner. An ordinary ACTION_VIEW/BROWSABLE
intent for recipe 14, with no forced package or component, resolved to
`com.coffeytable.coffey_table_recipes/.MainActivity` successfully. No app was
built, reinstalled, or force-approved as part of this repair. Existing phones
can retain cached failures or user browser preferences; allow time for retry or
check Open by default / Open supported links in Android app settings.

The app repository now has a read-only release checker and a deployment gate.
iOS AASA publication remains separate; this Android repair does not establish
iOS association or device validation.
