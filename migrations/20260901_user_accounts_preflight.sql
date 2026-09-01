-- Read-only checks to run before 20260901_add_user_accounts.sql.
-- Rows returned by the wallet queries need an explicit admin decision; the
-- migration intentionally does not guess which wallet owns existing funds.

SELECT VERSION() AS database_version;

SELECT
    s.user_id,
    u.email,
    COUNT(*) AS store_count,
    COUNT(DISTINCT s.wallet_path) AS wallet_count,
    GROUP_CONCAT(DISTINCT s.wallet_path ORDER BY s.wallet_path SEPARATOR '\n') AS wallet_paths
FROM stores AS s
JOIN users AS u ON u.id = s.user_id
WHERE s.user_id IS NOT NULL
GROUP BY s.user_id, u.email
HAVING COUNT(DISTINCT s.wallet_path) > 1;

SELECT
    s.wallet_path,
    COUNT(DISTINCT s.user_id) AS client_count,
    GROUP_CONCAT(DISTINCT s.user_id ORDER BY s.user_id) AS user_ids
FROM stores AS s
WHERE s.user_id IS NOT NULL
GROUP BY s.wallet_path
HAVING COUNT(DISTINCT s.user_id) > 1;

SELECT u.id, u.email
FROM users AS u
LEFT JOIN stores AS s ON s.user_id = u.id
WHERE u.role = 'client'
GROUP BY u.id, u.email
HAVING COUNT(s.id) = 0;
