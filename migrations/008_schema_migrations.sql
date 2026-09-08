-- Journal for the administrator migration tool. Existing manually applied migrations
-- are recognized structurally, never falsely recorded as executed by this tool.
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `migration` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `checksum` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `state` ENUM('Running','Applied','Failed') NOT NULL,
    `completed_statements` INT UNSIGNED NOT NULL DEFAULT 0,
    `started_at` BIGINT UNSIGNED NOT NULL,
    `finished_at` BIGINT UNSIGNED DEFAULT NULL,
    `admin_id` INT UNSIGNED NOT NULL,
    `error_code` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
