-- Stop workers before applying this migration through the admin database updater.
-- NULL distinguishes old balance snapshots from receipts calculated from transaction outputs.
ALTER TABLE invoices
    ADD COLUMN confirmed_output_sats BIGINT UNSIGNED DEFAULT NULL AFTER payment_observed_at,
    ADD COLUMN unconfirmed_output_sats BIGINT UNSIGNED DEFAULT NULL AFTER confirmed_output_sats;
