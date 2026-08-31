-- Read-only checks before 20260831_add_auth_login_attempts.sql.
SELECT 'users_table_missing' AS check_name,
       IF(EXISTS(
           SELECT 1
           FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       ), 0, 1) AS issue_count;

SELECT 'invalid_user_roles' AS check_name, COUNT(*) AS issue_count
FROM users
WHERE role IS NULL OR role NOT IN ('admin', 'client');

SELECT 'invalid_user_emails' AS check_name, COUNT(*) AS issue_count
FROM users
WHERE email = '' OR email <> TRIM(email) OR CHAR_LENGTH(email) > 254;

SELECT 'empty_password_hashes' AS check_name, COUNT(*) AS issue_count
FROM users
WHERE password_hash = '';

SELECT 'duplicate_normalized_emails' AS check_name, COUNT(*) AS issue_count
FROM (
    SELECT LOWER(TRIM(email)) AS normalized_email
    FROM users
    GROUP BY LOWER(TRIM(email))
    HAVING COUNT(*) > 1
) AS duplicates;

SELECT 'auth_login_attempts_already_present' AS check_name, COUNT(*) AS issue_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_login_attempts';
