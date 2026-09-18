-- Katalog layanan surat global.
-- Aman dijalankan ulang pada database yang sudah memakai migrasi 001-021.
SET NAMES utf8mb4;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN form_schema_json LONGTEXT NULL AFTER requirements_json',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'form_schema_json');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN schema_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER template_key',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'schema_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN submission_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'submission_enabled');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN availability_note VARCHAR(500) NULL AFTER submission_enabled',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'availability_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN minimum_app_version VARCHAR(50) NOT NULL DEFAULT ''0.0.0'' AFTER availability_note',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'minimum_app_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN source_updated_at DATETIME NULL AFTER minimum_app_version',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'source_updated_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN published_at DATETIME NULL AFTER source_updated_at',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'published_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN source_hash CHAR(64) NULL AFTER published_at',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'source_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_types ADD COLUMN source_revision BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_hash',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_types' AND COLUMN_NAME = 'source_revision');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE service_types MODIFY description VARCHAR(1000) NULL;

CREATE TABLE IF NOT EXISTS global_service_catalog_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  is_ready TINYINT(1) NOT NULL DEFAULT 0,
  last_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_hash CHAR(64) NULL,
  service_count INT UNSIGNED NOT NULL DEFAULT 0,
  published_by VARCHAR(160) NULL,
  published_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS village_service_overrides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  service_type_id INT UNSIGNED NOT NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  submission_enabled TINYINT(1) NULL,
  availability_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_village_service_override (village_id, service_type_id),
  KEY idx_village_service_override_visible (village_id, is_visible),
  CONSTRAINT fk_service_override_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_service_override_type FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transisi tanpa jeda layanan: ambil salinan aktif terbaru dari katalog desa.
-- Publikasi berikutnya dari server pusat akan mengganti sumber sementara ini.
INSERT INTO service_types (
  slug, name, short_name, icon, description, requirements_json,
  form_schema_json, template_key, schema_version, sort_order, is_active,
  submission_enabled, availability_note, minimum_app_version,
  source_updated_at, published_at, source_hash, source_revision
)
SELECT
  vc.service_key, vc.name, vc.short_name, vc.icon, vc.description,
  vc.requirements_json, vc.form_schema_json, vc.template_key,
  vc.schema_version, vc.sort_order, vc.is_active,
  vc.submission_enabled, vc.availability_note, '0.0.0',
  vc.source_updated_at, vc.published_at, vc.source_hash, vc.source_revision
FROM village_service_catalog vc
LEFT JOIN village_service_catalog newer
  ON newer.service_key = vc.service_key
 AND newer.is_active = 1
 AND (
      newer.source_revision > vc.source_revision
      OR (newer.source_revision = vc.source_revision AND newer.id > vc.id)
 )
WHERE vc.is_active = 1 AND newer.id IS NULL
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  short_name = VALUES(short_name),
  icon = VALUES(icon),
  description = VALUES(description),
  requirements_json = VALUES(requirements_json),
  form_schema_json = VALUES(form_schema_json),
  template_key = VALUES(template_key),
  schema_version = VALUES(schema_version),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  submission_enabled = VALUES(submission_enabled),
  availability_note = VALUES(availability_note),
  source_updated_at = VALUES(source_updated_at),
  published_at = VALUES(published_at),
  source_hash = VALUES(source_hash),
  source_revision = VALUES(source_revision);

INSERT INTO global_service_catalog_state (
  id, is_ready, last_revision, last_hash, service_count, published_by, published_at
)
-- Jangan aktifkan katalog global dari gabungan data lama. Selama publikasi pusat
-- pertama belum berhasil, PWA tetap membaca katalog per desa sebagai fallback.
SELECT
  1,
  0,
  COALESCE(MAX(source_revision), 0),
  NULL,
  COALESCE(SUM(IF(is_active = 1, 1, 0)), 0),
  'Migrasi katalog desa',
  MAX(published_at)
FROM service_types
ON DUPLICATE KEY UPDATE
  is_ready = global_service_catalog_state.is_ready,
  last_revision = GREATEST(global_service_catalog_state.last_revision, VALUES(last_revision)),
  service_count = IF(global_service_catalog_state.is_ready = 1, global_service_catalog_state.service_count, VALUES(service_count)),
  published_at = COALESCE(global_service_catalog_state.published_at, VALUES(published_at));

-- Memulihkan instalasi yang sempat menjalankan revisi awal migrasi 022. Hanya
-- status migrasi tanpa hash publikasi yang direset; snapshot pusat tetap utuh.
UPDATE global_service_catalog_state
SET is_ready = 0
WHERE id = 1
  AND published_by = 'Migrasi katalog desa'
  AND (last_hash IS NULL OR last_hash = '');
