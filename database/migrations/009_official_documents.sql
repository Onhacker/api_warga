-- Metadata dokumen resmi yang diterbitkan oleh instalasi SmartDesa lokal.
SET @official_hash_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'service_requests'
    AND column_name = 'document_sha256'
);
SET @official_hash_sql := IF(
  @official_hash_exists = 0,
  'ALTER TABLE service_requests ADD document_sha256 CHAR(64) NULL AFTER document_path',
  'SELECT 1'
);
PREPARE official_hash_stmt FROM @official_hash_sql;
EXECUTE official_hash_stmt;
DEALLOCATE PREPARE official_hash_stmt;

SET @official_size_exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'service_requests'
    AND column_name = 'document_size'
);
SET @official_size_sql := IF(
  @official_size_exists = 0,
  'ALTER TABLE service_requests ADD document_size BIGINT UNSIGNED NULL AFTER document_sha256',
  'SELECT 1'
);
PREPARE official_size_stmt FROM @official_size_sql;
EXECUTE official_size_stmt;
DEALLOCATE PREPARE official_size_stmt;
