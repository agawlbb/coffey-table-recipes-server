CREATE TABLE IF NOT EXISTS recipe_favorites (
 household_id BIGINT UNSIGNED NOT NULL,
 recipe_id BIGINT UNSIGNED NOT NULL,
 person_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (household_id, recipe_id, person_id),
 FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
 FOREIGN KEY (household_id, person_id) REFERENCES household_people(household_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
