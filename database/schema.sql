-- SmartDesa Warga central schema.
-- This database is intentionally separate from the local SmartDesa/update DB.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS village_tenants (
  id CHAR(36) NOT NULL PRIMARY KEY,
  province_code VARCHAR(20) NOT NULL,
  province_name VARCHAR(120) NOT NULL,
  regency_code VARCHAR(20) NOT NULL,
  regency_name VARCHAR(120) NOT NULL,
  district_code VARCHAR(20) NOT NULL,
  district_name VARCHAR(120) NOT NULL,
  village_code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  logo_path VARCHAR(255) NULL,
  settings_json JSON NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_village_region (regency_code, district_code, status),
  KEY idx_village_status (status, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  village_id CHAR(36) NULL,
  name VARCHAR(160) NOT NULL,
  username VARCHAR(100) NOT NULL UNIQUE,
  email VARCHAR(180) NULL UNIQUE,
  phone VARCHAR(30) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_users_scope (village_id, role_id, is_active),
  CONSTRAINT fk_warga_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_warga_users_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS citizen_profiles (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  village_id CHAR(36) NOT NULL,
  local_citizen_uuid CHAR(36) NULL,
  nik_hash CHAR(64) NULL,
  nik_encrypted VARBINARY(512) NULL,
  kk_hash CHAR(64) NULL,
  birth_date DATE NULL,
  gender VARCHAR(20) NULL,
  address_snapshot TEXT NULL,
  verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_citizen_village (village_id, verification_status),
  KEY idx_citizen_nik_hash (nik_hash),
  CONSTRAINT fk_citizen_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_citizen_profile_village FOREIGN KEY (village_id) REFERENCES village_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(180) NOT NULL,
  short_name VARCHAR(100) NOT NULL,
  icon VARCHAR(80) NOT NULL DEFAULT 'fa-file-alt',
  description VARCHAR(500) NULL,
  requirements_json JSON NULL,
  template_key VARCHAR(100) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_service_types_active (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_requests (
  id CHAR(36) NOT NULL PRIMARY KEY,
  request_code VARCHAR(50) NOT NULL UNIQUE,
  citizen_user_id BIGINT UNSIGNED NOT NULL,
  village_id CHAR(36) NOT NULL,
  service_type_id INT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'submitted',
  payload_json JSON NOT NULL,
  local_reference VARCHAR(160) NULL,
  document_path VARCHAR(500) NULL,
  local_sync_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  local_synced_at DATETIME NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_requests_citizen (citizen_user_id, submitted_at),
  KEY idx_requests_village_status (village_id, status, submitted_at),
  KEY idx_requests_sync (village_id, local_sync_status, updated_at),
  CONSTRAINT fk_requests_citizen FOREIGN KEY (citizen_user_id) REFERENCES users(id),
  CONSTRAINT fk_requests_village FOREIGN KEY (village_id) REFERENCES village_tenants(id),
  CONSTRAINT fk_requests_service FOREIGN KEY (service_type_id) REFERENCES service_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  note VARCHAR(1000) NULL,
  actor_id BIGINT UNSIGNED NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_request_history (request_id, occurred_at),
  CONSTRAINT fk_request_history_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_request_history_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_documents (
  id CHAR(36) NOT NULL PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  original_name VARCHAR(180) NOT NULL,
  stored_name VARCHAR(220) NOT NULL,
  storage_path VARCHAR(600) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_request_documents (request_id, created_at),
  CONSTRAINT fk_request_documents_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_request_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS village_installations (
  id CHAR(36) NOT NULL PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  installation_code VARCHAR(80) NOT NULL UNIQUE,
  sync_key_hash CHAR(64) NOT NULL,
  sync_secret_hash CHAR(64) NOT NULL,
  sync_secret_encrypted VARBINARY(1024) NULL,
  enrollment_code_hash CHAR(64) NULL,
  enrollment_expires_at DATETIME NULL,
  enrollment_used_at DATETIME NULL,
  enrollment_device_hash CHAR(64) NULL,
  app_version VARCHAR(50) NULL,
  last_seen_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_installations_village (village_id, status),
  KEY idx_installations_enrollment (enrollment_code_hash, status),
  CONSTRAINT fk_installations_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_enrollment_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_enrollment_attempt_ip (ip_hash, attempted_at),
  KEY idx_enrollment_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_request_nonces (
  installation_id CHAR(36) NOT NULL,
  nonce VARCHAR(128) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (installation_id, nonce),
  KEY idx_api_nonce_expiry (expires_at),
  CONSTRAINT fk_api_nonce_installation FOREIGN KEY (installation_id) REFERENCES village_installations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_messages (
  id CHAR(36) NOT NULL PRIMARY KEY,
  village_id CHAR(36) NOT NULL,
  installation_id CHAR(36) NULL,
  aggregate_type VARCHAR(80) NOT NULL,
  aggregate_id CHAR(36) NOT NULL,
  direction VARCHAR(30) NOT NULL,
  operation VARCHAR(30) NOT NULL,
  payload_json JSON NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  idempotency_key VARCHAR(180) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sync_queue (village_id, direction, status, available_at),
  KEY idx_sync_aggregate (aggregate_type, aggregate_id),
  CONSTRAINT fk_sync_village FOREIGN KEY (village_id) REFERENCES village_tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_sync_installation FOREIGN KEY (installation_id) REFERENCES village_installations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  request_id CHAR(36) NULL,
  title VARCHAR(180) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_failures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identity_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_identity (identity_hash, attempted_at),
  KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_warga_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
