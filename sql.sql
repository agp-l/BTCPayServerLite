-- Fresh-install schema for MariaDB 10.4+.
-- Select the target database before executing this file. The web installer does
-- this automatically and can create the database when the DB user is allowed to.

CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(254) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'client') NOT NULL DEFAULT 'client',
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    `session_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `last_login_at` BIGINT UNSIGNED DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `last_seen_at` BIGINT UNSIGNED DEFAULT NULL,
    `last_seen_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
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
VALUES ('registration_enabled', '1', NULL, UNIX_TIMESTAMP());

CREATE TABLE `auth_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identity_hash` BINARY(32) NOT NULL,
    `attempted_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_auth_attempt_identity` (`identity_hash`, `attempted_at`),
    KEY `idx_auth_attempt_cleanup` (`attempted_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stores` (
    `id` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `api_key` VARCHAR(255)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `wallet_path` VARCHAR(255) DEFAULT NULL,
    `address_source` ENUM('xpub', 'electrum') NOT NULL DEFAULT 'xpub',
    `xpub` VARCHAR(255) DEFAULT NULL,
    `xpub_script_type` VARCHAR(20) NOT NULL DEFAULT 'p2wpkh',
    `xpub_last_index` INT UNSIGNED NOT NULL DEFAULT 0,
    `user_id` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stores_api_key` (`api_key`),
    KEY `idx_stores_user` (`user_id`),
    CONSTRAINT `fk_stores_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `client_wallets` (
    `user_id` INT UNSIGNED NOT NULL,
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

CREATE TABLE `password_reset_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
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

CREATE TABLE `invoices` (
    `id` VARCHAR(50) NOT NULL,
    `store_id` VARCHAR(50) NOT NULL,
    `btc_address` VARCHAR(100) NOT NULL,
    `address_source` ENUM('xpub', 'electrum') NOT NULL DEFAULT 'electrum',
    `address_index` INT UNSIGNED DEFAULT NULL,
    `derivation_path` VARCHAR(50) DEFAULT NULL,
    `amount` DECIMAL(16,8) NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'New',
    `metadata` JSON DEFAULT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `expires_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `store_id` (`store_id`),
    KEY `status` (`status`),
    KEY `idx_invoices_monitoring` (`status`, `expires_at`, `store_id`),
    CONSTRAINT `fk_invoices_store`
        FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payouts` (
    `id` VARCHAR(50) NOT NULL,
    `store_id` VARCHAR(50) NOT NULL,
    `idempotency_hash` BINARY(32) NOT NULL,
    `request_hash` BINARY(32) NOT NULL,
    `destination` VARCHAR(150) NOT NULL,
    `original_currency` VARCHAR(10) NOT NULL,
    `original_amount` DECIMAL(20,8) NOT NULL,
    `payout_amount` DECIMAL(16,8) NOT NULL,
    `exchange_fee` DECIMAL(16,8) NOT NULL DEFAULT 0,
    `fee_rate_sat_vb` INT UNSIGNED DEFAULT NULL,
    `state` VARCHAR(30) NOT NULL DEFAULT 'AwaitingApproval',
    `revision` INT UNSIGNED NOT NULL DEFAULT 0,
    `raw_transaction` LONGTEXT
        CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `txid` CHAR(64)
        CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `last_error` VARCHAR(255) DEFAULT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `updated_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payout_store_idempotency` (`store_id`, `idempotency_hash`),
    KEY `idx_payout_store_created` (`store_id`, `created_at`),
    KEY `idx_payout_state_updated` (`state`, `updated_at`),
    CONSTRAINT `fk_payout_store`
        FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `webhooks` (
    `id` VARCHAR(50) NOT NULL,
    `store_id` VARCHAR(50) NOT NULL,
    `url` TEXT NOT NULL,
    `secret` VARCHAR(255) NOT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `url_hash` BINARY(32)
        GENERATED ALWAYS AS (UNHEX(SHA2(`url`, 256))) PERSISTENT,
    PRIMARY KEY (`id`),
    KEY `store_id` (`store_id`),
    KEY `idx_webhook_store_created` (`store_id`, `created_at`),
    UNIQUE KEY `uq_webhooks_store_url` (`store_id`, `url_hash`),
    CONSTRAINT `fk_webhooks_store`
        FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `webhook_deliveries` (
    `id` VARCHAR(50) NOT NULL,
    `webhook_id` VARCHAR(50) NOT NULL,
    `invoice_id` VARCHAR(50) NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `payload` LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
        CHECK (JSON_VALID(`payload`)),
    `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at` BIGINT UNSIGNED NOT NULL,
    `locked_at` BIGINT UNSIGNED DEFAULT NULL,
    `lock_token` CHAR(32)
        CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `last_http_status` SMALLINT UNSIGNED DEFAULT NULL,
    `last_primary_ip` VARCHAR(45) DEFAULT NULL,
    `last_error` VARCHAR(255) DEFAULT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `delivered_at` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_webhook_delivery_event`
        (`webhook_id`, `invoice_id`, `event_type`),
    KEY `idx_webhook_delivery_due`
        (`status`, `next_attempt_at`, `locked_at`),
    KEY `idx_webhook_delivery_invoice` (`invoice_id`),
    CONSTRAINT `fk_webhook_delivery_webhook`
        FOREIGN KEY (`webhook_id`) REFERENCES `webhooks` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_webhook_delivery_invoice`
        FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_request_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id VARCHAR(50) DEFAULT NULL,
    method VARCHAR(10) NOT NULL,
    request_path VARCHAR(255) NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL,
    duration_ms INT UNSIGNED NOT NULL,
    client_ip VARCHAR(45) DEFAULT NULL,
    integration_name VARCHAR(100) DEFAULT NULL,
    integration_version VARCHAR(50) DEFAULT NULL,
    shop_origin VARCHAR(255) DEFAULT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_api_request_created (created_at),
    KEY idx_api_request_store_created (store_id, created_at),
    KEY idx_api_request_status_created (http_status, created_at),
    CONSTRAINT fk_api_request_store
        FOREIGN KEY (store_id) REFERENCES stores (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_integrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id VARCHAR(50) NOT NULL,
    integration_key BINARY(32) NOT NULL,
    name VARCHAR(100) NOT NULL,
    version VARCHAR(50) DEFAULT NULL,
    shop_origin VARCHAR(255) DEFAULT NULL,
    first_seen_at BIGINT UNSIGNED NOT NULL,
    last_seen_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_store_integration (store_id, integration_key),
    KEY idx_store_integration_seen (last_seen_at),
    CONSTRAINT fk_store_integration_store
        FOREIGN KEY (store_id) REFERENCES stores (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `api_idempotency_keys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `store_id` VARCHAR(50) NOT NULL,
    `idempotency_key` VARCHAR(128) NOT NULL,
    `request_hash` BINARY(32) NOT NULL,
    `response_code` SMALLINT UNSIGNED NOT NULL,
    `response_body` LONGTEXT NOT NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_store_idempotency_key` (`store_id`, `idempotency_key`),
    KEY `idx_idempotency_created` (`created_at`),
    CONSTRAINT `fk_idempotency_store`
        FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
