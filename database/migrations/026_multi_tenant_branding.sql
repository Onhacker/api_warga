-- Branding publik per kabupaten/tenant.
-- Baris lama id=1 dipertahankan sebagai fallback "default" agar PWA lama
-- tetap berjalan sampai WARGA_TENANT_CODE dikonfigurasi pada domainnya.
SET NAMES utf8mb4;

ALTER TABLE app_public_branding
  MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD COLUMN IF NOT EXISTS tenant_code VARCHAR(30) NOT NULL DEFAULT 'default' AFTER id,
  ADD COLUMN IF NOT EXISTS tenant_name VARCHAR(120) NOT NULL DEFAULT '' AFTER tenant_code;

UPDATE app_public_branding
SET tenant_code = CASE
  WHEN id = 1 THEN 'default'
  ELSE CONCAT('legacy-', id)
END
WHERE tenant_code IS NULL
   OR TRIM(tenant_code) = ''
   OR (tenant_code = 'default' AND id <> 1);

SET @branding_tenant_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'app_public_branding'
    AND index_name = 'uniq_app_public_branding_tenant'
);
SET @branding_tenant_index_sql := IF(
  @branding_tenant_index_exists = 0,
  'ALTER TABLE app_public_branding ADD UNIQUE KEY uniq_app_public_branding_tenant (tenant_code)',
  'SELECT 1'
);
PREPARE branding_tenant_index_stmt FROM @branding_tenant_index_sql;
EXECUTE branding_tenant_index_stmt;
DEALLOCATE PREPARE branding_tenant_index_stmt;
