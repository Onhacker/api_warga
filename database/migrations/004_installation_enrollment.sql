-- Aktivasi installer universal SmartDesa.
-- Jalankan sekali setelah 001, 002, dan 003 pada database produksi existing.
ALTER TABLE village_installations
  ADD COLUMN IF NOT EXISTS enrollment_code_hash CHAR(64) NULL AFTER sync_secret_encrypted,
  ADD COLUMN IF NOT EXISTS enrollment_expires_at DATETIME NULL AFTER enrollment_code_hash,
  ADD COLUMN IF NOT EXISTS enrollment_used_at DATETIME NULL AFTER enrollment_expires_at,
  ADD COLUMN IF NOT EXISTS enrollment_device_hash CHAR(64) NULL AFTER enrollment_used_at;

SET @enrollment_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'village_installations'
    AND index_name = 'idx_installations_enrollment'
);
SET @enrollment_index_sql := IF(
  @enrollment_index_exists = 0,
  'ALTER TABLE village_installations ADD KEY idx_installations_enrollment (enrollment_code_hash, status)',
  'SELECT 1'
);
PREPARE enrollment_index_stmt FROM @enrollment_index_sql;
EXECUTE enrollment_index_stmt;
DEALLOCATE PREPARE enrollment_index_stmt;

CREATE TABLE IF NOT EXISTS installation_enrollment_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_enrollment_attempt_ip (ip_hash, attempted_at),
  KEY idx_enrollment_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
