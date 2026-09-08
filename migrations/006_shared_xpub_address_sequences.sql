-- Shared receive-index high water survives store deletion and key-prefix aliases.
CREATE TABLE IF NOT EXISTS `xpub_address_sequences` (
    `key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `next_index` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
