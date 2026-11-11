CREATE TABLE IF NOT EXISTS rate_limits (
    rate_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    window_started_at BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    INDEX idx_rate_limits_window (window_started_at)
) ENGINE=InnoDB;