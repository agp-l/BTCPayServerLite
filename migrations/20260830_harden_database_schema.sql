-- BTCPayServerLite database hardening for MariaDB 10.4+
--
-- This migration contains no application data or secrets. Run it once, only
-- after taking a database backup. ALTER TABLE statements commit implicitly in
-- MariaDB, so restore the backup if a later DDL statement fails.
--
-- The legacy invoices.address column is deliberately retained until every
-- caller has been audited and a separate data migration is available.

DROP PROCEDURE IF EXISTS btcpaylite_schema_preflight;

DELIMITER //

CREATE PROCEDURE btcpaylite_schema_preflight()
BEGIN
    DECLARE issue_count BIGINT UNSIGNED DEFAULT 0;

    SELECT COUNT(*) INTO issue_count
      FROM invoices AS invoice
 LEFT JOIN stores AS store ON store.id = invoice.store_id
     WHERE store.id IS NULL;
    IF issue_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: invoices contain unknown store_id values.';
    END IF;

    SELECT COUNT(*) INTO issue_count
      FROM webhooks AS webhook
 LEFT JOIN stores AS store ON store.id = webhook.store_id
     WHERE store.id IS NULL;
    IF issue_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: webhooks contain unknown store_id values.';
    END IF;

    SELECT COUNT(*) INTO issue_count
      FROM (
            SELECT BINARY api_key
              FROM stores
          GROUP BY BINARY api_key
            HAVING COUNT(*) > 1
           ) AS duplicate_store_keys;
    IF issue_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: duplicate store API keys exist.';
    END IF;

    SELECT COUNT(*) INTO issue_count
      FROM (
            SELECT store_id, UNHEX(SHA2(url, 256)) AS url_hash
              FROM webhooks
          GROUP BY store_id, UNHEX(SHA2(url, 256))
            HAVING COUNT(*) > 1
           ) AS duplicate_webhooks;
    IF issue_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: duplicate webhook URLs exist for a store.';
    END IF;

    SELECT COUNT(*) INTO issue_count
      FROM invoices
     WHERE created_at < 0
        OR expires_at < 0
        OR expires_at <= created_at;
    IF issue_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped: invalid invoice timestamps exist.';
    END IF;
END//

DELIMITER ;

CALL btcpaylite_schema_preflight();
DROP PROCEDURE btcpaylite_schema_preflight;

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
