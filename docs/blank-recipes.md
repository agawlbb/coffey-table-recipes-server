# Blank recipes

Blank recipes use the existing `recipes.source_type` value `blank`; no migration is required. POST `/households/{id}/recipe-blank` authenticates household membership and returns an unsaved draft and category options. It never invokes the import or image providers. Save through the existing recipe editor endpoint, requiring a title and at least one valid category assignment. Cooking content and nutrition are rejected for blanks. `favorite_on_create: true` creates the current household person's favorite in the same transaction.

Existing browse, category, search, favorites, photo editing and trash operations apply. Import replaces the blank through a revision-checked update of the same ID; photo and favorite ownership remain intact. Blank share-link requests fail with 422; direct public share pages return 404. Imported recipes can be shared normally.

Run `php tests/blank_recipes.php` for disposable in-memory validation of required fields, authorization, search/category browsing, favorites, photo preservation, revision conflicts, import conversion and public sharing restrictions. The deployment workflow includes this test. Fixtures adapt MySQL locking syntax for SQLite and do not prove InnoDB concurrency semantics. No persistent local database is used.
