-- Handshake otomatis untuk installer universal SmartDesa.
-- Jalankan setelah 004_installation_enrollment.sql.
-- Hanya hash key/nonce yang disimpan; nilai bootstrap tidak pernah masuk DB.
CREATE TABLE IF NOT EXISTS auto_enrollment_nonces (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  key_hash CHAR(64) NOT NULL,
  nonce_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_auto_enrollment_nonce (key_hash, nonce_hash),
  KEY idx_auto_enrollment_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
