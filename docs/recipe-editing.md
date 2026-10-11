# Recipe editing API

`POST /api/v1/households/{household}/recipes/{recipe}` accepts the recipe detail
draft and returns `{ "recipe": ... }`. Any member of the recipe's household can
edit it. Authentication and membership are checked before processing the draft.
The `revision` field is required and checked under a row lock. A mismatch returns
409 without changes; invalid fields return 422. A recipe outside the household
returns 404. Successful saves increment revision and set updating-user attribution.

The request contains recipe content plus complete `groups`, `ingredients`,
`steps`, `actions`, `photos`, `tags`, `category_assignments`, and `pairings` lists,
and optional `nutrition`. Existing child IDs are preserved and must belong to
this recipe; new child IDs start with `new-`. List order becomes stored position.
Group and step temporary IDs are mapped to inserted IDs; actions stay attached to
their steps. All database changes are atomic. Import bookkeeping/source extraction,
favorites, cooking history, and recipe creation metadata are preserved.

Detail GET responses now include category assignments, pairing notes, and household
recipe/category choices for editing. Parent relationships cannot point outside the
household or form a cycle. Pairings cannot reference the same recipe or another
household. Nutrition only advances to the new revision with
`nutrition_confirmed: true`; otherwise its prior revision is preserved so the
app marks it stale. No migration is required.

New photos use `image_base64`; existing photo storage metadata is loaded from the
database rather than trusted from the client. JPEG/PNG/WebP signatures, dimensions,
and sizes are validated (5 MB/24 MP per photo, 20 photos, 20 MB uploaded per save).
The recipe endpoint has a 30 MB JSON limit; other endpoints retain 16 KB limits.
Ensure hosting request limits permit the intended maximum payload. Random media
filenames are stored in `media/recipes`; `.htaccess` blocks directory listing and
non-image extensions. Uploaded images are excluded from Git. Files written by a
failed save are removed. Removing a saved photo removes its recipe association;
previously committed media files are retained for recovery rather than deleted
inside the transaction.

Local validation on 2026-10-11: PHP syntax checks passed; 28 recipe edit checks and
55 recipe schema regression checks passed in disposable development databases.
Tests cover revision conflicts, household isolation, atomic rollback, exact
fractions, stable IDs, step/group mappings, photo signature rejection, cover
uniqueness, protected photo metadata, upload cleanup, categories, pairings,
nutrition stale status, and removals. Production recipe data was not edited for
testing. Flutter validation is recorded in the app repository.

Deployment prepared for `/home/hosting_account/coffeybean.com/CoffeyTableRecipes` with
index.php, src/bootstrap.php, src/RecipeService.php, src/RecipeEditorService.php,
and media/recipes/.htaccess. The initial package was compared with a downloaded
backup. A concurrent sharing deployment landed during this work; the final router
was uploaded with both the editing and sharing routes and its downloaded SHA-256
matches the verified local index.php. The hosted backup is
storage/before-recipe-editor-20261011.zip. Config, dependencies, database structure,
database contents, and hosted Flutter app are preserved.

Hosted deployment completed on 2026-10-11. Health returned 200; anonymous recipe
and sharing reads returned 401; the protected editor source and backup returned
403. Automatic approval review rejected a live POST probe because it might mutate
production recipe data. No live save was performed; save behavior is verified by
the 28 disposable-database checks. Physical device camera permissions and an
authenticated hosted save remain manual verification items.
