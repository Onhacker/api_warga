-- Audit pemindahan satu perangkat SmartDesa dari kampung sumber ke tujuan.
-- Secret asli tidak pernah disimpan pada tabel audit.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS installation_rebind_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operation_id CHAR(36) NOT NULL,
  source_village_id CHAR(36) NOT NULL,
  target_village_id CHAR(36) NOT NULL,
  source_installation_id CHAR(36) NOT NULL,
  target_installation_id CHAR(36) NOT NULL,
  source_village_code VARCHAR(13) NOT NULL,
  target_village_code VARCHAR(13) NOT NULL,
  source_device_hash_prefix CHAR(12) NULL,
  target_device_hash_prefix CHAR(12) NULL,
  source_hardware_hash_prefix CHAR(12) NULL,
  target_hardware_hash_prefix CHAR(12) NULL,
  reason VARCHAR(500) NOT NULL,
  actor VARCHAR(160) NOT NULL,
  details_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_installation_rebind_operation (operation_id),
  KEY idx_installation_rebind_source (source_village_code, created_at),
  KEY idx_installation_rebind_target (target_village_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
