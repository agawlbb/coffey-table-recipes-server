-- One-time setup before deploying SQL child-mode policy; routine deployment does not run this.
ALTER TABLE users
    ADD COLUMN child_mode BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN child_mode_pin_hash VARCHAR(255) NULL,
    ADD COLUMN child_mode_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN child_mode_blocked_until BIGINT UNSIGNED NULL,
    ADD COLUMN child_mode_reset_after BIGINT UNSIGNED NULL;
