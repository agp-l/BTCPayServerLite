-- Read-only checks before 20260830_add_webhook_deliveries.sql.
-- Every issue_count must be zero. The final preview_count is informational.

SELECT check_name, issue_count
FROM (
    SELECT
        'empty_webhook_secrets' AS check_name,
        COUNT(*) AS issue_count
    FROM webhooks
    WHERE secret IS NULL OR TRIM(secret) = ''

    UNION ALL

    SELECT
        'invalid_invoice_statuses',
        COUNT(*)
    FROM invoices
    WHERE status NOT IN ('New', 'Processing', 'Settled', 'Expired')

    UNION ALL

    SELECT
        'invalid_invoice_timestamps',
        COUNT(*)
    FROM invoices
    WHERE created_at < 1 OR expires_at < created_at

    UNION ALL

    SELECT
        'webhook_created_at_already_present',
        COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'webhooks'
      AND COLUMN_NAME = 'created_at'

    UNION ALL

    SELECT
        'webhook_deliveries_already_present',
        COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'webhook_deliveries'
) AS checks
ORDER BY check_name;

SELECT
    'historical_terminal_delivery_markers' AS preview_name,
    COUNT(*) AS preview_count
FROM invoices AS invoice
JOIN webhooks AS webhook ON webhook.store_id = invoice.store_id
WHERE invoice.status IN ('Settled', 'Expired');
