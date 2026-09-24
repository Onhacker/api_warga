-- Metadata publik untuk verifikasi surat yang diterbitkan langsung oleh
-- instalasi SmartDesa lokal. Isi HTML/PDF, NIK, dan lampiran tidak disalin.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS local_letter_verifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL,
  village_id CHAR(36) NOT NULL,
  service_slug VARCHAR(120) NOT NULL,
  service_name VARCHAR(180) NOT NULL,
  letter_number VARCHAR(160) NOT NULL,
  issued_at DATETIME NOT NULL,
  metadata_fingerprint CHAR(64) NOT NULL,
  source_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_local_letter_public (public_id),
  KEY idx_local_letter_village (village_id, updated_at),
  CONSTRAINT fk_local_letter_village FOREIGN KEY (village_id)
    REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
