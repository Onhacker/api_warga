-- Sinkronisasi atomik dan urutan event.
-- Aman dijalankan ulang pada database API yang sudah memakai migrasi 001-014.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS village_resident_snapshot_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  snapshot_id CHAR(64) NOT NULL,
  batch_index INT UNSIGNED NOT NULL,
  batch_total INT UNSIGNED NOT NULL,
  batch_hash CHAR(64) NOT NULL,
  resident_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_resident_snapshot_batch (village_id, snapshot_id, batch_index),
  KEY idx_resident_batch_snapshot (village_id, snapshot_id),
  CONSTRAINT fk_resident_batch_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_requests ADD COLUMN event_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER status',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests' AND COLUMN_NAME = 'event_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE village_resident_snapshot_batches ADD COLUMN batch_hash CHAR(64) NOT NULL DEFAULT '''' AFTER batch_total',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'village_resident_snapshot_batches' AND COLUMN_NAME = 'batch_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sync_messages ADD COLUMN event_version BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER operation',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_messages' AND COLUMN_NAME = 'event_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sync_messages ADD COLUMN payload_fingerprint CHAR(64) NULL AFTER event_version',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_messages' AND COLUMN_NAME = 'payload_fingerprint');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE village_resident_snapshots ADD COLUMN directory_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER snapshot_id',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'village_resident_snapshots' AND COLUMN_NAME = 'directory_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE village_resident_snapshots ADD COLUMN directory_hash CHAR(64) NULL AFTER directory_version',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'village_resident_snapshots' AND COLUMN_NAME = 'directory_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE village_resident_snapshots ADD KEY idx_resident_snapshot_version (village_id, directory_version, finalized)',
    'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'village_resident_snapshots' AND INDEX_NAME = 'idx_resident_snapshot_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE village_service_catalog ADD COLUMN submission_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'village_service_catalog' AND COLUMN_NAME = 'submission_enabled');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE village_service_catalog ADD COLUMN availability_note VARCHAR(500) NULL AFTER submission_enabled',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'village_service_catalog' AND COLUMN_NAME = 'availability_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE village_service_catalog ADD COLUMN source_revision BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_hash',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'village_service_catalog' AND COLUMN_NAME = 'source_revision');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS village_service_catalog_state (
  village_id CHAR(36) NOT NULL PRIMARY KEY,
  last_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_hash CHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_catalog_state_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS village_resident_directory_staging (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  snapshot_id CHAR(64) NOT NULL,
  local_citizen_key VARCHAR(120) NOT NULL,
  nik_hash CHAR(64) NOT NULL,
  kk_hash CHAR(64) NOT NULL,
  name_hash CHAR(64) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  birth_date DATE NULL,
  gender VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_resident_stage_source (village_id, snapshot_id, local_citizen_key),
  UNIQUE KEY uniq_resident_stage_nik (village_id, snapshot_id, nik_hash),
  KEY idx_resident_stage_snapshot (village_id, snapshot_id),
  CONSTRAINT fk_resident_stage_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
