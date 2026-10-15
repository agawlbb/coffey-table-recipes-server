# Parent category administration

`GET /api/v1/admin/parent-categories` lists shared root categories with `id`, `label`, `child_count`, `assignment_count`, and `can_delete`.

`POST` on the same endpoint accepts `action` (`add`, `rename`, `delete`), string `id` for rename/delete, and `label` for add/rename. Names are trimmed, required, limited to 120 characters, and checked against other parent names using the database collation. IDs, slugs, artwork, and existing assignments survive renames.

Both operations require an enabled application administrator. Household ownership alone grants no access. Changes affect the catalog used by every household. No schema migration is required.

Deletion requires no child categories and no direct recipe assignments in any household, including assignments belonging to recently deleted recipes. Even an empty child prevents deleting its parent. Parent-row locks and existing foreign keys protect against concurrent assignments/child creation. Nonempty deletion returns 409 without removing data.

Validation: `php tests/parent_categories.php` runs 29 isolated in-memory checks for authorization, disabled accounts, add/rename/delete, validation, duplicates, child protection, and assignments from another household. It does not access a persistent database and does not model MySQL locking. Existing recipe deletion fixtures also pass (40 checks).
