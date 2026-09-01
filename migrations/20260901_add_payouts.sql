-- Adds the durable payout ledger used by direct BTC payouts and future pull payments.
-- Run once after a verified database backup.
USE `btcpay_lite`;

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
