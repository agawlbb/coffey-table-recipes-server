CREATE TABLE IF NOT EXISTS shopping_connections (
 token_hash CHAR(64) NOT NULL PRIMARY KEY,
 household_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX shopping_connections_household (household_id),
 FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
