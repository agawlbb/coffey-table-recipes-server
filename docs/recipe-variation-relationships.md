# Recipe variation relationships and saves

AI generation produces an unsaved editor draft. Only the existing explicit
editor create request (`POST /api/v1/households/{household}/recipes`) persists the
reviewed content. The create uses fresh ingredient, group, step, action and photo
IDs; IDs copied from the original are rejected. Saving a variation does not edit
the original's recipe content, revision, source or child records.

## Existing schema, no migration

`database/migrations/003_recipes.sql` already defines:

- Nullable `recipes.parent_recipe_id` with a composite foreign key from
  `(household_id, parent_recipe_id)` to `recipes(household_id, id)`.
- Unique `(household_id, import_key)` and existing `source_type` / `source_text`
  provenance columns.

`010_recipe_deletion.sql` adds the existing trash lifecycle fields. These fields
cover the variation relationship, source and save-idempotency needs; no new
table, flag, migration or historical backfill is needed. Existing manually
linked variations retain their relationships. Source type alone is never used
to decide whether a saved recipe is a variation.

## Relationship contract

- `parent_recipe_id` is a positive canonical recipe ID or null. The selected
  parent must be live and belong to the same household. Missing, foreign,
  malformed, deleted and self parents are rejected with 422.
- Every editor save locks the household row before any recipe row, serializing
  relationship changes within that household. Ancestor traversal uses locking
  reads so InnoDB's default REPEATABLE READ cannot validate a stale chain. All
  ancestors are traversed, including trashed ancestors, to reject indirect or
  already-corrupt cycles. Only the directly selected parent must be live.
- Browse, detail and related entries expose boolean `is_variation`, derived
  from non-null `parent_recipe_id`. Client-supplied `is_variation` is ignored.
- `detail.related` contains the live same-household immediate parent, siblings
  sharing that parent, and direct children, excluding the current recipe. Each
  row has `relationship: "parent" | "sibling" | "child"`. Descendants beyond
  direct children and unrelated roots are not returned. Nested variations show
  both their own parent and their own children.
- Trashing an original hides its related entry without clearing its surviving
  variation links. Existing permanent-purge behavior eventually clears the
  parent link and increments child revisions; derived variation markers then
  become false. This change does not alter the existing deletion lifecycle.

## Retrying approved saves

A new AI variation must supply `source_type: "ai_variation"`, a valid parent and
a 64-character lowercase SHA-256 `import_key` from its generated draft. The
draft-generation service binds that identifier to the request. The editor looks
up the key under the household serialization lock. When a live saved recipe has
the same key, source type and parent, it returns the existing recipe without
reapplying body fields, incrementing revision, writing photos or changing the
original. This makes duplicate taps and retrying a lost successful response safe.

A key associated with a different source type or parent returns 409. A retry
whose saved AI variation is now in Recently deleted also returns 409, preserving
the user's deletion instead of recreating it. Regular imports keep their
existing duplicate-import 409 behavior and transactional transfer of trashed
import-key reservations on re-import. Keys remain scoped to a household, and
failed saves roll back any transfer.

## Local verification

`php tests/recipe_variation_relations.php` uses an in-memory SQLite fixture built
from the existing schema column names. It covers isolated copy creation,
idempotent retries, mismatched-key conflicts, household isolation, derived
markers, adjacent relationship labels, invalid parents, cycles, rollback,
original preservation and trash visibility. It also checks household-first lock
ordering and locking ancestry queries. The fixture adapts `FOR UPDATE` syntax;
it does not demonstrate real InnoDB blocking, deadlock handling or DDL validity.
It uses no hosted database, production data or AI calls.
