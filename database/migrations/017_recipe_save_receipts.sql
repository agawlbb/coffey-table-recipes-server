CREATE TABLE IF NOT EXISTS recipe_save_receipts (
    household_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    save_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    body_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response LONGTEXT NOT NULL,
    PRIMARY KEY (household_id,user_id,save_id),
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
