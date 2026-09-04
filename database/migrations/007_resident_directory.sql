-- Direktori penduduk tersinkron per kampung.
-- NIK dan No. KK hanya disimpan sebagai HMAC di server pusat.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE citizen_profiles
  ADD COLUMN IF NOT EXISTS local_citizen_key VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS name_hash CHAR(64) NULL;

SET @profile_source_index_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'citizen_profiles'
    AND index_name = 'uniq_citizen_source'
);
SET @profile_source_index_sql := IF(
  @profile_source_index_exists = 0,
  'ALTER TABLE citizen_profiles ADD UNIQUE KEY uniq_citizen_source (village_id, local_citizen_key)',
  'SELECT 1'
);
PREPARE profile_source_index_stmt FROM @profile_source_index_sql;
EXECUTE profile_source_index_stmt;
DEALLOCATE PREPARE profile_source_index_stmt;

CREATE TABLE IF NOT EXISTS village_resident_directory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  local_citizen_key VARCHAR(120) NOT NULL,
  nik_hash CHAR(64) NOT NULL,
  kk_hash CHAR(64) NOT NULL,
  name_hash CHAR(64) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  birth_date DATE NULL,
  gender VARCHAR(20) NULL,
  snapshot_id CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_resident_source (village_id, local_citizen_key),
  UNIQUE KEY uniq_resident_nik (village_id, nik_hash),
  KEY idx_resident_match (village_id, nik_hash, kk_hash, status),
  KEY idx_resident_snapshot (village_id, snapshot_id, status),
  CONSTRAINT fk_resident_directory_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS village_resident_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  snapshot_id CHAR(64) NOT NULL,
  snapshot_created_at DATETIME NOT NULL,
  batch_total INT UNSIGNED NOT NULL DEFAULT 1,
  finalized TINYINT(1) NOT NULL DEFAULT 0,
  finalized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_resident_snapshot (village_id, snapshot_id),
  KEY idx_resident_snapshot_latest (village_id, snapshot_created_at),
  CONSTRAINT fk_resident_snapshot_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS village_resident_snapshot_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  snapshot_id CHAR(64) NOT NULL,
  batch_index INT UNSIGNED NOT NULL,
  batch_total INT UNSIGNED NOT NULL,
  resident_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_resident_snapshot_batch (village_id, snapshot_id, batch_index),
  KEY idx_resident_batch_snapshot (village_id, snapshot_id),
  CONSTRAINT fk_resident_batch_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resident_verification_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_resident_attempt_ip (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
