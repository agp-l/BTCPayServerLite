-- Fresh-install schema for MariaDB 10.4+.
CREATE DATABASE IF NOT EXISTS `btcpay_lite`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `btcpay_lite`;

CREATE TABLE `stores` (
    `id` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `api_key` VARCHAR(255)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `wallet_path` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stores_api_key` (`api_key`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoices` (
    `id` VARCHAR(50) NOT NULL,
    `store_id` VARCHAR(50) NOT NULL,
    `btc_address` VARCHAR(100) NOT NULL,
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

-- Development-only sample. Replace both credentials and wallet path before use.
INSERT INTO `stores` (`id`, `name`, `api_key`, `wallet_path`)
VALUES (
    'store_12345',
    'Můj První E-shop',
    'replace-with-a-random-store-api-key',
    '/opt/btcpay_wallets/replace-with-wallet-path'
);
