ALTER TABLE users
    ADD COLUMN email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0,
    ADD KEY user_email (email);

CREATE TABLE household_invitations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    invited_by_user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('sending', 'pending', 'accepted', 'declined', 'failed', 'expired', 'cancelled') NOT NULL DEFAULT 'sending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    sent_at DATETIME(6) NULL,
    responded_at DATETIME(6) NULL,
    responded_by_user_id BIGINT UNSIGNED NULL,
    active_person_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('sending', 'pending') THEN person_id ELSE NULL END) STORED,
    active_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN status IN ('sending', 'pending') THEN email ELSE NULL END) STORED,
    UNIQUE KEY person_pending_invitation (active_person_id),
    UNIQUE KEY household_pending_email (household_id, active_email),
    KEY invitee_pending (email, status, expires_at),
    KEY inviter_recent (invited_by_user_id, created_at),
    FOREIGN KEY (household_id, person_id) REFERENCES household_people(household_id, id),
    FOREIGN KEY (household_id, invited_by_user_id) REFERENCES household_memberships(household_id, user_id),
    FOREIGN KEY (responded_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
