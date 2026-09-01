-- User accounts, registration policy and canonical client-wallet ownership.
-- Run after the schema currently present on main.

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active' AFTER `role`,
    ADD COLUMN IF NOT EXISTS `session_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `status`,
    ADD COLUMN IF NOT EXISTS `last_login_at` BIGINT UNSIGNED DEFAULT NULL AFTER `session_version`,
    ADD COLUMN IF NOT EXISTS `last_login_ip` VARCHAR(45) DEFAULT NULL AFTER `last_login_at`,
    ADD COLUMN IF NOT EXISTS `last_seen_at` BIGINT UNSIGNED DEFAULT NULL AFTER `last_login_ip`,
    ADD COLUMN IF NOT EXISTS `last_seen_ip` VARCHAR(45) DEFAULT NULL AFTER `last_seen_at`,
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    -- Existing installations created from main use a signed INT users.id.
    `updated_by` INT DEFAULT NULL,
    `updated_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`setting_key`),
    KEY `idx_app_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_app_settings_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_by`, `updated_at`)
VALUES ('registration_enabled', '1', NULL, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

CREATE TABLE IF NOT EXISTS `client_wallets` (
    `user_id` INT NOT NULL,
    `wallet_path` VARCHAR(255) NOT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `updated_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_client_wallets_path` (`wallet_path`),
    CONSTRAINT `fk_client_wallets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `token_hash` BINARY(32) NOT NULL,
    `expires_at` BIGINT UNSIGNED NOT NULL,
    `used_at` BIGINT UNSIGNED DEFAULT NULL,
    `requested_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_reset_token` (`token_hash`),
    KEY `idx_password_reset_user` (`user_id`, `created_at`),
    KEY `idx_password_reset_expiry` (`expires_at`, `used_at`),
    CONSTRAINT `fk_password_reset_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
