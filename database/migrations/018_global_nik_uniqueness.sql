-- NIK hanya boleh dimiliki satu penduduk aktif di seluruh layanan warga.
--
-- Migration ini sengaja gagal ketika data lama masih mempunyai hash NIK
-- ganda. Jangan gunakan INSERT IGNORE atau menghapus salah satu baris secara
-- otomatis; selesaikan konflik terlebih dahulu agar data kependudukan tidak
-- hilang tanpa keputusan administrator.
SET NAMES utf8mb4;

SET @profile_nik_index_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'citizen_profiles'
    AND index_name = 'uniq_citizen_nik_global'
);
SET @profile_nik_index_sql := IF(
  @profile_nik_index_exists = 0,
  'ALTER TABLE citizen_profiles ADD UNIQUE KEY uniq_citizen_nik_global (nik_hash)',
  'SELECT 1'
);
PREPARE profile_nik_index_stmt FROM @profile_nik_index_sql;
EXECUTE profile_nik_index_stmt;
DEALLOCATE PREPARE profile_nik_index_stmt;

SET @resident_nik_index_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'village_resident_directory'
    AND index_name = 'uniq_resident_nik_global'
);
SET @resident_nik_index_sql := IF(
  @resident_nik_index_exists = 0,
  'ALTER TABLE village_resident_directory ADD UNIQUE KEY uniq_resident_nik_global (nik_hash)',
  'SELECT 1'
);
PREPARE resident_nik_index_stmt FROM @resident_nik_index_sql;
EXECUTE resident_nik_index_stmt;
DEALLOCATE PREPARE resident_nik_index_stmt;
