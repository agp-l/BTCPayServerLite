-- Run exactly once after a clean 20260831_auth_preflight.sql result and backup.
CREATE TABLE `auth_login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identity_hash` BINARY(32) NOT NULL,
    `attempted_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_auth_login_attempt_identity` (`identity_hash`, `attempted_at`),
    KEY `idx_auth_login_attempt_cleanup` (`attempted_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
