-- Read-only preflight for 20260830_harden_database_schema.sql.
-- Every issue_count must be zero before the schema migration is executed.

SELECT 'orphan_invoices' AS check_name, COUNT(*) AS issue_count
  FROM invoices AS invoice
  LEFT JOIN stores AS store ON store.id = invoice.store_id
 WHERE store.id IS NULL

UNION ALL

SELECT 'orphan_webhooks', COUNT(*)
  FROM webhooks AS webhook
  LEFT JOIN stores AS store ON store.id = webhook.store_id
 WHERE store.id IS NULL

UNION ALL

SELECT 'duplicate_store_api_keys', COUNT(*)
  FROM (
        SELECT BINARY api_key
          FROM stores
      GROUP BY BINARY api_key
        HAVING COUNT(*) > 1
       ) AS duplicate_store_keys

UNION ALL

SELECT 'duplicate_store_webhook_urls', COUNT(*)
  FROM (
        SELECT store_id, UNHEX(SHA2(url, 256)) AS url_hash
          FROM webhooks
      GROUP BY store_id, UNHEX(SHA2(url, 256))
        HAVING COUNT(*) > 1
       ) AS duplicate_webhooks

UNION ALL

SELECT 'invalid_invoice_timestamps', COUNT(*)
  FROM invoices
 WHERE created_at < 0
    OR expires_at < 0
    OR expires_at <= created_at;
