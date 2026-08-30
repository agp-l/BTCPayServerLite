-- BTCPayServerLite database hardening for MariaDB 10.4+
--
-- This migration contains no application data or secrets. Run it once, only
-- after taking a database backup and confirming that every issue_count from
-- 20260830_database_preflight.sql is zero. ALTER TABLE statements commit
-- implicitly in MariaDB, so restore the backup if a later DDL statement fails.
--
-- The legacy invoices.address column is deliberately retained until every
-- caller has been audited and a separate data migration is available.

ALTER TABLE stores
    MODIFY api_key VARCHAR(255)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    ADD UNIQUE KEY uq_stores_api_key (api_key);

ALTER TABLE invoices
    MODIFY created_at BIGINT UNSIGNED NOT NULL,
    MODIFY expires_at BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_invoices_monitoring (status, expires_at, store_id),
    ADD CONSTRAINT fk_invoices_store
        FOREIGN KEY (store_id) REFERENCES stores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE webhooks
    ADD COLUMN url_hash BINARY(32)
        GENERATED ALWAYS AS (UNHEX(SHA2(url, 256))) PERSISTENT,
    ADD UNIQUE KEY uq_webhooks_store_url (store_id, url_hash),
    ADD CONSTRAINT fk_webhooks_store
        FOREIGN KEY (store_id) REFERENCES stores (id)
        ON UPDATE CASCADE ON DELETE CASCADE;
