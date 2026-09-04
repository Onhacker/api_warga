-- Cegah dua akun PWA mengacu pada penduduk lokal yang sama.
-- Nilai NULL lama tetap diperbolehkan lebih dari satu oleh unique index MariaDB.
SET @citizen_source_unique_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'citizen_profiles'
    AND index_name = 'uniq_citizen_source'
);
SET @citizen_source_unique_sql := IF(
  @citizen_source_unique_exists = 0,
  'ALTER TABLE citizen_profiles ADD UNIQUE KEY uniq_citizen_source (village_id, local_citizen_key)',
  'SELECT 1'
);
PREPARE citizen_source_unique_stmt FROM @citizen_source_unique_sql;
EXECUTE citizen_source_unique_stmt;
DEALLOCATE PREPARE citizen_source_unique_stmt;

SET @citizen_source_legacy_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'citizen_profiles'
    AND index_name = 'idx_citizen_source'
);
SET @citizen_source_legacy_sql := IF(
  @citizen_source_legacy_exists > 0,
  'ALTER TABLE citizen_profiles DROP INDEX idx_citizen_source',
  'SELECT 1'
);
PREPARE citizen_source_legacy_stmt FROM @citizen_source_legacy_sql;
EXECUTE citizen_source_legacy_stmt;
DEALLOCATE PREPARE citizen_source_legacy_stmt;
