ALTER TABLE users ADD COLUMN account_role ENUM('user', 'administrator') NOT NULL DEFAULT 'user';
