-- Label bentuk wilayah dipublikasikan per tenant bersama branding PWA.
SET NAMES utf8mb4;

ALTER TABLE app_public_branding
  ADD COLUMN IF NOT EXISTS bentuk_lembaga VARCHAR(100) NOT NULL DEFAULT 'Desa' AFTER tagline,
  ADD COLUMN IF NOT EXISTS bentuk_kecamatan VARCHAR(100) NOT NULL DEFAULT 'Kecamatan' AFTER bentuk_lembaga,
  ADD COLUMN IF NOT EXISTS region_labels_managed TINYINT(1) NOT NULL DEFAULT 0 AFTER bentuk_kecamatan;

UPDATE app_public_branding
SET bentuk_lembaga = 'Desa'
WHERE bentuk_lembaga IS NULL OR TRIM(bentuk_lembaga) = '';

UPDATE app_public_branding
SET bentuk_kecamatan = 'Kecamatan'
WHERE bentuk_kecamatan IS NULL OR TRIM(bentuk_kecamatan) = '';
