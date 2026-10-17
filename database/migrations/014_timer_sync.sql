CREATE TABLE IF NOT EXISTS timer_devices (
    installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(10) NOT NULL,
    push_token VARCHAR(4096) NULL,
    receive_timers BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX timer_devices_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_timers (
    user_id BIGINT UNSIGNED NOT NULL,
    timer_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_device CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(300) NOT NULL,
    deadline_ms BIGINT NOT NULL,
    cancelled BOOLEAN NOT NULL DEFAULT FALSE,
    revision BIGINT NOT NULL DEFAULT 1,
    last_operation CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, timer_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
