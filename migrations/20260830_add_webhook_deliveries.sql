-- Persistent webhook outbox for MariaDB 10.4+.
-- Run once after taking a database backup. Existing webhook registrations use
-- created_at = 0 so they continue to cover active invoices created before this
-- migration. New registrations apply only to invoices created afterwards.

ALTER TABLE webhooks
    ADD COLUMN created_at BIGINT UNSIGNED DEFAULT NULL AFTER secret;

UPDATE webhooks SET created_at = 0 WHERE created_at IS NULL;

ALTER TABLE webhooks
    MODIFY created_at BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_webhook_store_created (store_id, created_at);

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

-- Do not replay historical terminal invoices during the first processor run.
-- Their actual delivery history is unknowable, so the safe migration policy
-- is to begin durable tracking from this migration forward.
INSERT INTO webhook_deliveries (
    id,
    webhook_id,
    invoice_id,
    event_type,
    payload,
    status,
    attempts,
    next_attempt_at,
    created_at,
    delivered_at
)
SELECT
    CONCAT(
        'wd_m_',
        MD5(CONCAT(webhook.id, '|', invoice.id, '|', invoice.status))
    ),
    webhook.id,
    invoice.id,
    CASE invoice.status
        WHEN 'Settled' THEN 'InvoiceSettled'
        ELSE 'InvoiceExpired'
    END,
    JSON_OBJECT(
        'deliveryId', CONCAT(
            'wd_m_',
            MD5(CONCAT(webhook.id, '|', invoice.id, '|', invoice.status))
        ),
        'storeId', invoice.store_id,
        'invoiceId', invoice.id,
        'type', CASE invoice.status
            WHEN 'Settled' THEN 'InvoiceSettled'
            ELSE 'InvoiceExpired'
        END,
        'timestamp', invoice.expires_at
    ),
    'Delivered',
    0,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM invoices AS invoice
JOIN webhooks AS webhook ON webhook.store_id = invoice.store_id
WHERE invoice.status IN ('Settled', 'Expired')
  AND webhook.created_at <= invoice.created_at;
