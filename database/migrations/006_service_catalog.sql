-- Katalog Master Surat per desa dan formulir dinamis Layanan Warga.
-- Jalankan sekali pada database warga yang sudah memakai schema lama.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS village_service_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  service_key VARCHAR(80) NOT NULL,
  name VARCHAR(180) NOT NULL,
  short_name VARCHAR(100) NOT NULL,
  icon VARCHAR(80) NOT NULL DEFAULT 'fa-file-alt',
  description VARCHAR(1000) NULL,
  requirements_json LONGTEXT NULL,
  form_schema_json LONGTEXT NULL,
  template_key VARCHAR(120) NULL,
  schema_version INT UNSIGNED NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source_updated_at DATETIME NULL,
  published_at DATETIME NULL,
  source_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_village_service_key (village_id, service_key),
  KEY idx_village_service_active (village_id, is_active, sort_order),
  CONSTRAINT fk_village_service_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE service_requests
  ADD COLUMN IF NOT EXISTS catalog_service_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS form_schema_version INT UNSIGNED NULL;

SET @catalog_request_index_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'service_requests'
    AND index_name = 'idx_requests_catalog'
);
SET @catalog_request_index_sql := IF(
  @catalog_request_index_exists = 0,
  'ALTER TABLE service_requests ADD KEY idx_requests_catalog (catalog_service_id)',
  'SELECT 1'
);
PREPARE catalog_request_index_stmt FROM @catalog_request_index_sql;
EXECUTE catalog_request_index_stmt;
DEALLOCATE PREPARE catalog_request_index_stmt;

ALTER TABLE request_documents
  ADD COLUMN IF NOT EXISTS field_key VARCHAR(100) NULL;

SET @catalog_document_index_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'request_documents'
    AND index_name = 'idx_request_documents_field'
);
SET @catalog_document_index_sql := IF(
  @catalog_document_index_exists = 0,
  'ALTER TABLE request_documents ADD KEY idx_request_documents_field (request_id, field_key)',
  'SELECT 1'
);
PREPARE catalog_document_index_stmt FROM @catalog_document_index_sql;
EXECUTE catalog_document_index_stmt;
DEALLOCATE PREPARE catalog_document_index_stmt;

SET FOREIGN_KEY_CHECKS = 1;
