-- Stop payment workers while applying this migration, then deploy the matching code.
-- Previous columns held maxima, not cumulative outputs or reliable current balances.
ALTER TABLE invoices
    CHANGE confirmed_received_sats confirmed_balance_sats BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CHANGE unconfirmed_received_sats mempool_delta_sats BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN payment_observed_at INT UNSIGNED DEFAULT NULL AFTER mempool_delta_sats;
-- Keep old payment evidence until the first worker refresh (observation time is NULL).
-- The worker uses this evidence only to retain Processing, never to fabricate settlement.
UPDATE invoices SET payment_processing_token = NULL, payment_processing_until = NULL, next_check_at = NULL;
