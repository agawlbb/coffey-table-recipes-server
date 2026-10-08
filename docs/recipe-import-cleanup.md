# Development recipe cleanup and source photos

`bin/cleanup-recipe-import.php` operates on the reviewed eight-recipe local bundle. It matches `(household_id, import_key)` and confirms source identity, the original stored extraction, and active owner/admin membership of household 2/user 6. It never reimports or changes recipe IDs. No schema migration is needed.

```powershell
C:\xampp\php\php.exe bin\cleanup-recipe-import.php --dry-run --bundle C:\Users\developer\StudioProjects\coffey_table_recipes\recipe-import\import-ready.json
C:\xampp\php\php.exe bin\cleanup-recipe-import.php --apply --bundle C:\Users\developer\StudioProjects\coffey_table_recipes\recipe-import\import-ready.json
```

Dry runs do not write database rows. They write protected local reports with old/new values, reasons, source evidence, and image verification failures. Apply uses prepared statements, scoped predicates, a recipe lock, and one transaction/revision increment per changed recipe. Second runs preserve photos, positions, and user-selected covers and produce no further changes. Reports are saved with unique filenames, plus latest-run convenience files, under HTTP-blocked and Git-ignored `storage/`.

## Mapping rules

- Remove only the exact importer bookkeeping note; mixed notes require review. Genuine cooking notes remain intact.
- Collapse a yield of `N; N Servings` or `N; N serves` to its descriptive phrase. Keep contradictory/range yields for review. Do not infer structured servings.
- For the verified Bob's Red Mill Bran Flax Muffins source only, use the original final instruction's `15 medium muffins` instead of `15 people`. Keep servings unknown and retain the instruction and original source extraction.
- For that same source, remove the exact category labels `Bob & Charlee's Recipe Box`, `Category`, `Dietary Needs`, and `Soy Free`. The first is a publisher collection, the next two are headings, and the last is an unverified dietary claim. Keep `Muffins`. All original labels remain in `source_text`. No blanket filter applies to other publishers or unfamiliar labels.
- The other seven recipes' existing category/cuisine tags are meaningful and retained. No inferred dietary labels, nutrition, actions, or history are added.

## Photos

`RecipeImportTools` handles Recipe.image strings, arrays, and ImageObject values, preferring `contentUrl`. Relative references resolve against the stored final source page. Only HTTP/HTTPS references with hosts and no embedded credentials are accepted. Images come from the original Recipe structured data, not arbitrary page thumbnails.

Verification rejects private, loopback, link-local, reserved, and carrier-grade NAT addresses, including IPv6. DNS is pinned for each request. Each manual redirect is revalidated. TLS certificate checks stay enabled; redirects, headers, bytes (8 MiB), and request time (20 seconds) are bounded. Status, image content type, image bytes, and dimensions must agree. Access failures are reported without substitutes or bypassing restrictions. Verification bytes stay in memory and are never hosted. The verification cache expires after one hour and remains in protected storage.

Photo references retain source URLs, verified MIME/dimensions, publisher/author attribution, and source captions only when provided. Identical resolved URLs are deduplicated. Existing covers remain selected; the first successful structured image becomes cover only when there is no cover. Additional structured crop variants remain gallery references.

## Recovery

Before each changed recipe is committed, the command saves a snapshot containing its original recipe, tags, and photos, then its expected post-cleanup state. Original ingredient/step rows are untouched. The snapshot path is printed by apply and resides in `storage/recipe-cleanup-backup-*.json`.

```powershell
C:\xampp\php\php.exe bin\cleanup-recipe-import.php --restore C:\xampp\htdocs\CoffeyTableRecipes\storage\recipe-cleanup-backup-<timestamp>-<suffix>.json
```

Restore requires the verified active destination and an exact match to the recorded post-cleanup state. It refuses to overwrite later edits. It restores yield/notes/revision/updater/timestamp, original tags and photos, preserving recipe IDs/import keys and all unrelated content. Recovery is transactional per recipe; inspect any conflict before proceeding. Retain snapshots privately; never commit them.

## October 8, 2026 validation

Changed recipes: IDs 9–16. Removed eight bookkeeping notes, corrected three yield strings, removed four muffin category artifacts, and added 18 photo references with eight covers. Each recipe advanced once to revision 2. A second dry run and apply produced zero changes. A private before/after audit confirmed unchanged recipe IDs/import keys, original quantities/units/language/source_text, ingredients, steps, relationship/history/nutrition rows, and foreign-household data.

All 18 references passed network/image-byte validation. A headless Chrome check on the local app origin decoded all 18 as HTML images; cross-origin image decoding succeeded for 12. Bon Appétit (recipe 11, three URLs) and Midwest Foodie Smoked Queso (recipe 16, three URLs) failed cross-origin decoding. Flutter web's renderer may show its existing fallback for these sources. No source URL rewrite, CORS workaround, proxy, or local image hosting was introduced.

Smoked Queso's `10; 10-12 servings` remains ambiguous and unchanged. The Android emulator is available but its app requires user sign-in. Browser automation was unavailable because the Windows helper failed to initialize, so the authenticated Browse recipes/detail flow is still unverified in Chrome and Android; the isolated Chrome image check does not substitute for that flow.

Run `tests/recipe_import.php`, `tests/recipe_schema.php`, and `tests/authentication.php`. The import tests cover mixed image shapes, metadata-only objects, relative/unsafe URLs, public destination checks, narrow category mapping, yield ambiguity, and repeated database photo backfill with preservation of a user-selected cover. Database tests use disposable databases.
