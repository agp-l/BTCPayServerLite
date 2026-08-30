-- Persistent webhook outbox for MariaDB 10.4+.
-- Run once after taking a database backup. This migration does not alter or
-- delete existing invoice, store, or webhook rows.

CREATE TABLE webhook_deliveries (
    id VARCHAR(50) NOT NULL,
    webhook_id VARCHAR(50) NOT NULL,
    invoice_id VARCHAR(50) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    payload LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
        CHECK (JSON_VALID(payload)),
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at BIGINT UNSIGNED NOT NULL,
    locked_at BIGINT UNSIGNED DEFAULT NULL,
    lock_token CHAR(32)
        CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    last_http_status SMALLINT UNSIGNED DEFAULT NULL,
    last_primary_ip VARCHAR(45) DEFAULT NULL,
    last_error VARCHAR(255) DEFAULT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    delivered_at BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_delivery_event (webhook_id, invoice_id, event_type),
    KEY idx_webhook_delivery_due (status, next_attempt_at, locked_at),
    KEY idx_webhook_delivery_invoice (invoice_id),
    CONSTRAINT fk_webhook_delivery_webhook
        FOREIGN KEY (webhook_id) REFERENCES webhooks (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_webhook_delivery_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
