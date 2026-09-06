-- Nonce replay protection untuk endpoint monitoring server-to-server.
-- Tidak menyimpan API key atau secret dalam bentuk asli.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS monitor_request_nonces (
  key_hash CHAR(64) NOT NULL,
  nonce_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (key_hash, nonce_hash),
  KEY idx_monitor_nonce_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
