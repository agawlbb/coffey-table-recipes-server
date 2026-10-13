CREATE TABLE IF NOT EXISTS deleted_recipe_cooking_history (
    household_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    recipe_title VARCHAR(300) NOT NULL,
    recipe_revision BIGINT UNSIGNED NOT NULL,
    servings_made DECIMAL(12,4) NULL,
    notes TEXT NULL,
    PRIMARY KEY (event_id, recipe_id),
    FOREIGN KEY (household_id, event_id) REFERENCES cooking_events(household_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recipes
    ADD COLUMN deleted_at DATETIME(6) NULL,
    ADD COLUMN deleted_by_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN purge_after DATETIME(6) NULL,
    ADD KEY recipe_trash (household_id, deleted_at, purge_after),
    ADD KEY recipe_expiry (purge_after);

