-- Pause recipe writes while applying and deploying the matching backend.
-- Content stays under its original storage household. Membership controls access.
CREATE TABLE IF NOT EXISTS recipe_households (
    recipe_id BIGINT UNSIGNED NOT NULL,
    household_id BIGINT UNSIGNED NOT NULL,
    removed_at DATETIME(6) NULL,
    PRIMARY KEY (recipe_id, household_id),
    KEY household_recipes (household_id, removed_at),
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    FOREIGN KEY (household_id) REFERENCES households(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO recipe_households (recipe_id,household_id,removed_at)
SELECT id,household_id,deleted_at FROM recipes
WHERE NOT EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=recipes.id AND h.household_id=recipes.household_id);
ALTER TABLE recipes ADD COLUMN trash_household_id BIGINT UNSIGNED NULL;
UPDATE recipes SET trash_household_id=household_id WHERE deleted_at IS NOT NULL;
-- Editors from attached households must be attributable without adding them to
-- the original household. Replace the composite attribution foreign key only.
SET @editor_fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recipes' AND COLUMN_NAME='updated_by_user_id' AND REFERENCED_TABLE_NAME='household_memberships' LIMIT 1);
SET @editor_sql = IF(@editor_fk IS NULL,'SELECT 1',CONCAT('ALTER TABLE recipes DROP FOREIGN KEY ',CHAR(96),REPLACE(@editor_fk,CHAR(96),CONCAT(CHAR(96),CHAR(96))),CHAR(96)));
PREPARE editor_statement FROM @editor_sql;
EXECUTE editor_statement;
DEALLOCATE PREPARE editor_statement;
ALTER TABLE recipes ADD CONSTRAINT recipe_editor_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id);
