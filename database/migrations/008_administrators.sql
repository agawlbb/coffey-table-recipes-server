ALTER TABLE users ADD COLUMN account_role ENUM('user', 'administrator') NOT NULL DEFAULT 'user';

UPDATE users SET account_role = 'administrator'
WHERE email = 'admin@example.test' AND email_verified = 1 AND disabled = 0;
