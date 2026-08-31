-- Jalankan sekali pada database warga yang dibuat sebelum API terpisah.
ALTER TABLE village_installations
  ADD COLUMN IF NOT EXISTS sync_secret_encrypted VARBINARY(1024) NULL AFTER sync_secret_hash;

CREATE TABLE IF NOT EXISTS api_request_nonces (
  installation_id CHAR(36) NOT NULL,
  nonce VARCHAR(128) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (installation_id, nonce),
  KEY idx_api_nonce_expiry (expires_at),
  CONSTRAINT fk_api_nonce_installation FOREIGN KEY (installation_id) REFERENCES village_installations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
