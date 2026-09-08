-- Persistent wallet-to-public-sequence binding; never cascade-delete with stores.
CREATE TABLE IF NOT EXISTS `wallet_receive_ranges` (
    `wallet_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `wallet_path` VARCHAR(1024) NOT NULL,
    `key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `xpub` VARCHAR(255) NOT NULL,
    `script_type` VARCHAR(20) NOT NULL,
    `initial_next_index` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `registered_next_index` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checked_at` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`wallet_hash`),
    KEY `idx_receive_key` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
