-- Minimal operational request metadata. No credentials or request bodies.

CREATE TABLE IF NOT EXISTS api_request_log (
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

CREATE TABLE IF NOT EXISTS store_integrations (
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
