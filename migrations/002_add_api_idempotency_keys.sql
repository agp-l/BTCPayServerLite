-- Migration: Add API Idempotency Keys table for Greenfield API
CREATE TABLE IF NOT EXISTS `api_idempotency_keys` (
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
