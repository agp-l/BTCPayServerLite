CREATE TABLE IF NOT EXISTS payment_worker_runtime (
    source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    run_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) NOT NULL,
    started_at BIGINT UNSIGNED NOT NULL,
    finished_at BIGINT UNSIGNED NULL,
    last_success_at BIGINT UNSIGNED NULL,
    last_failed_at BIGINT UNSIGNED NULL,
    scanned INT UNSIGNED NOT NULL DEFAULT 0,
    transitioned INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    deliveries_queued INT UNSIGNED NOT NULL DEFAULT 0,
    error_type VARCHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
