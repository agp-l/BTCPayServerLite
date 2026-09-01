-- Read-only checks before 20260901_add_payouts.sql.
-- Every issue_count must be 0 before running the migration.
SELECT 'stores_table_missing' AS check_name,
       IF(EXISTS(
           SELECT 1
           FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stores'
       ), 0, 1) AS issue_count;

SELECT 'stores_not_innodb' AS check_name, COUNT(*) AS issue_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'stores'
  AND ENGINE <> 'InnoDB';

SELECT 'payouts_already_present' AS check_name, COUNT(*) AS issue_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'payouts';
