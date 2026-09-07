-- Apply while API writers are stopped. Preserve all completed response bytes.
ALTER TABLE api_idempotency_keys
    MODIFY idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD COLUMN state ENUM('Pending','Completed','Failed') NOT NULL DEFAULT 'Pending' AFTER request_hash,
    ADD COLUMN resource_id VARCHAR(50) DEFAULT NULL AFTER state,
    ADD COLUMN resource_data LONGTEXT DEFAULT NULL AFTER resource_id,
    ADD UNIQUE KEY uq_idempotency_resource (resource_id);
UPDATE api_idempotency_keys
   SET state = CASE WHEN response_code >= 400 THEN 'Failed' ELSE 'Completed' END
 WHERE response_code > 0;
-- Legacy anonymous claims cannot safely be re-executed: they may already own an
-- invoice. Mark them explicitly for reconciliation rather than duplicating it.
UPDATE api_idempotency_keys
   SET state = 'Failed', response_code = 409,
       response_body = '{"code":"409","message":"Legacy pending operation requires invoice reconciliation; it will not be recreated."}'
 WHERE response_code = 0;
