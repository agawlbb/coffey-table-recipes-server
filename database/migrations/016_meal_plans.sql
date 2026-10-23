CREATE TABLE IF NOT EXISTS meal_plans (
    household_id BIGINT UNSIGNED PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    time_zone VARCHAR(64) NOT NULL DEFAULT 'America/New_York',
    FOREIGN KEY (household_id) REFERENCES households(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dated entries are durable history. No rollover job or preparation confirmation
-- is required. Snapshots survive recipe removal, edits and eventual purging.
CREATE TABLE IF NOT EXISTS meal_plan_entries (
    household_id BIGINT UNSIGNED NOT NULL,
    id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plan_date DATE NULL,
    kind ENUM('recipe','leftovers','restaurant') NOT NULL,
    recipe_id BIGINT UNSIGNED NULL,
    recipe_identity BIGINT UNSIGNED NULL,
    title VARCHAR(300) NOT NULL,
    recipe_revision BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (household_id,id),
    KEY meal_plan_dates (household_id,plan_date),
    KEY meal_recipe_history (household_id,recipe_identity,plan_date),
    FOREIGN KEY (household_id) REFERENCES households(id),
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
