-- Reset password PWA melalui OTP email yang dikirim SmartDesa pusat.
-- Token, OTP, email, dan alamat IP hanya disimpan sebagai hash.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS warga_password_reset_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_token_hash CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_warga_password_reset_token (request_token_hash),
  KEY idx_warga_password_reset_email (email_hash, created_at),
  KEY idx_warga_password_reset_ip (ip_hash, created_at),
  KEY idx_warga_password_reset_status (status, expires_at),
  KEY idx_warga_password_reset_user (user_id, status),
  CONSTRAINT fk_warga_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
