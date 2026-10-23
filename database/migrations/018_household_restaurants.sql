CREATE TABLE IF NOT EXISTS household_restaurant_lists (
 household_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 items_json LONGTEXT NOT NULL,
 CONSTRAINT fk_restaurant_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE meal_plan_entries ADD COLUMN restaurant_id VARCHAR(64) NULL, ADD COLUMN restaurant_logo_reference TEXT NULL;
