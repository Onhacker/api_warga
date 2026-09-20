-- OTP perubahan email/telepon/password dan branding publik PWA.
SET NAMES utf8mb4;

ALTER TABLE warga_password_reset_requests
  ADD COLUMN IF NOT EXISTS purpose VARCHAR(24) NOT NULL DEFAULT 'password_reset' AFTER user_id,
  ADD COLUMN IF NOT EXISTS target_email_hash CHAR(64) NULL AFTER email_hash,
  ADD COLUMN IF NOT EXISTS target_phone_hash CHAR(64) NULL AFTER target_email_hash;

CREATE TABLE IF NOT EXISTS app_public_branding (
  id TINYINT UNSIGNED NOT NULL,
  nama_sistem VARCHAR(100) NOT NULL DEFAULT 'SIDAPULIK',
  kepanjangan VARCHAR(180) NOT NULL DEFAULT '',
  tagline VARCHAR(255) NOT NULL DEFAULT '',
  updated_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_public_branding (id, nama_sistem, kepanjangan, tagline, updated_at)
SELECT 1, 'SIDAPULIK', '', 'Bersama Membangun Kampung Digital', NOW()
WHERE NOT EXISTS (SELECT 1 FROM app_public_branding WHERE id = 1);
