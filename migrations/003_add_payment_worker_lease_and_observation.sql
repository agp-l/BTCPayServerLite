-- Migration 003: Add PaymentWorker lease tokens and persisted payment observation
-- Enables scalable, batch-capable background monitoring and pure-DB checkout reads.

ALTER TABLE `invoices`
    ADD COLUMN `payment_processing_token` VARCHAR(64) DEFAULT NULL AFTER `metadata`,
    ADD COLUMN `payment_processing_until` INT UNSIGNED DEFAULT NULL AFTER `payment_processing_token`,
    ADD COLUMN `last_checked_at` INT UNSIGNED DEFAULT NULL AFTER `payment_processing_until`,
    ADD COLUMN `next_check_at` INT UNSIGNED DEFAULT NULL AFTER `last_checked_at`,
    ADD COLUMN `confirmed_received_sats` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `next_check_at`,
    ADD COLUMN `unconfirmed_received_sats` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `confirmed_received_sats`,
    ADD KEY `idx_invoices_lease` (`payment_processing_until`, `next_check_at`, `status`),
    ADD KEY `idx_invoices_token` (`payment_processing_token`);
