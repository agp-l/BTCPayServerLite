-- Migration: Add XPUB derivation support, per-wallet and worker indices, and idempotency tracking

ALTER TABLE `stores`
    ADD COLUMN `address_source` ENUM('xpub', 'electrum') NOT NULL DEFAULT 'electrum' AFTER `wallet_path`,
    ADD COLUMN `xpub` TEXT DEFAULT NULL AFTER `address_source`,
    ADD COLUMN `derivation_path` VARCHAR(100) NOT NULL DEFAULT "m/84'/0'/0'/0" AFTER `xpub`;

CREATE TABLE IF NOT EXISTS `store_address_indices` (
    `store_id` VARCHAR(50) NOT NULL,
    `last_index` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`store_id`),
    CONSTRAINT `fk_store_address_indices_store`
        FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `invoices`
    ADD COLUMN `address_source` VARCHAR(20) NOT NULL DEFAULT 'electrum' AFTER `btc_address`,
    ADD COLUMN `address_index` INT UNSIGNED DEFAULT NULL AFTER `address_source`,
    ADD COLUMN `derivation_path` VARCHAR(100) DEFAULT NULL AFTER `address_index`,
    ADD COLUMN `idempotency_key` VARCHAR(128) DEFAULT NULL AFTER `derivation_path`,
    ADD COLUMN `amount_sats` BIGINT UNSIGNED DEFAULT NULL AFTER `amount`,
    ADD COLUMN `confirmed_received_sats` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `amount_sats`,
    ADD COLUMN `unconfirmed_received_sats` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `confirmed_received_sats`,
    ADD COLUMN `last_checked_at` BIGINT UNSIGNED DEFAULT NULL AFTER `expires_at`,
    ADD COLUMN `next_check_at` BIGINT UNSIGNED DEFAULT NULL AFTER `last_checked_at`,
    ADD UNIQUE KEY `uq_invoice_store_idempotency` (`store_id`, `idempotency_key`),
    ADD KEY `idx_invoices_payment_worker` (`status`, `next_check_at`);
