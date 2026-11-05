USE erhtci_feedback;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
  bucket_hash CHAR(64) NOT NULL,
  attempts INT UNSIGNED NOT NULL,
  window_started_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (bucket_hash),
  KEY idx_auth_rate_limits_window (window_started_at)
) ENGINE=InnoDB;
