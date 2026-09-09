-- Optional device login credentials; requires existing users/session_version.
CREATE TABLE IF NOT EXISTS `remembered_logins` (
    `selector` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `validator_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `previous_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `session_version` INT UNSIGNED NOT NULL,
    `expires_at` BIGINT UNSIGNED NOT NULL,
    `rotated_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`selector`),
    KEY `idx_remembered_expiry` (`expires_at`),
    KEY `idx_remembered_user` (`user_id`),
    CONSTRAINT `fk_remembered_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
