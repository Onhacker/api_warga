-- Preserve PWA password changes unless Administrator explicitly resets them.
SET @staff_credential_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warga_staff_sources'
    AND COLUMN_NAME = 'credential_fingerprint'
);
SET @staff_credential_sql = IF(@staff_credential_column = 0,
  'ALTER TABLE warga_staff_sources ADD credential_fingerprint CHAR(64) NULL',
  'SELECT 1');
PREPARE staff_credential_statement FROM @staff_credential_sql;
EXECUTE staff_credential_statement;
DEALLOCATE PREPARE staff_credential_statement;
