-- Migration 001: Add XPUB support and address provenance fields

ALTER TABLE `stores`
    ADD COLUMN `address_source` ENUM('xpub', 'electrum') NOT NULL DEFAULT 'xpub' AFTER `wallet_path`,
    ADD COLUMN `xpub` VARCHAR(255) DEFAULT NULL AFTER `address_source`,
    ADD COLUMN `xpub_script_type` VARCHAR(20) NOT NULL DEFAULT 'p2wpkh' AFTER `xpub`,
    ADD COLUMN `xpub_last_index` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `xpub_script_type`,
    MODIFY COLUMN `wallet_path` VARCHAR(255) DEFAULT NULL;

ALTER TABLE `invoices`
    ADD COLUMN `address_source` ENUM('xpub', 'electrum') NOT NULL DEFAULT 'electrum' AFTER `btc_address`,
    ADD COLUMN `address_index` INT UNSIGNED DEFAULT NULL AFTER `address_source`,
    ADD COLUMN `derivation_path` VARCHAR(50) DEFAULT NULL AFTER `address_index`;
