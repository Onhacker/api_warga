-- Shared API/PWA database. Safe to run repeatedly.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS warga_announcements (
 id CHAR(36) PRIMARY KEY, village_id CHAR(36) NOT NULL, author_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(180) NOT NULL, body TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'published',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_announcement_village (village_id,status,created_at),
 FOREIGN KEY (village_id) REFERENCES village_tenants(id),
 FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS warga_complaints (
 id CHAR(36) PRIMARY KEY, village_id CHAR(36) NOT NULL, citizen_user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(180) NOT NULL, body TEXT NOT NULL, location VARCHAR(255) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'submitted',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_complaint_village (village_id,status,created_at),
 KEY idx_complaint_citizen (citizen_user_id,created_at),
 FOREIGN KEY (village_id) REFERENCES village_tenants(id),
 FOREIGN KEY (citizen_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS warga_complaint_replies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, complaint_id CHAR(36) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, message TEXT NOT NULL, status VARCHAR(20) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_complaint_reply (complaint_id,id),
 FOREIGN KEY (complaint_id) REFERENCES warga_complaints(id) ON DELETE CASCADE,
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS warga_notification_targets (
 notification_id CHAR(36) PRIMARY KEY, target_path VARCHAR(255) NOT NULL,
 FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS warga_push_subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 endpoint_hash CHAR(64) NOT NULL UNIQUE, endpoint VARCHAR(2048) NOT NULL,
 public_key VARCHAR(255) NOT NULL, auth_token VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_push_user (user_id),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS warga_push_deliveries (
 notification_id CHAR(36) NOT NULL, subscription_id BIGINT UNSIGNED NOT NULL,
 attempts INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'pending',
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (notification_id,subscription_id),
 KEY idx_push_retry (status,next_attempt_at),
 FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
 FOREIGN KEY (subscription_id) REFERENCES warga_push_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS warga_staff_sources (
 village_id CHAR(36) NOT NULL, local_id CHAR(36) NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 source_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (village_id,local_id), UNIQUE KEY uniq_staff_source_user (user_id),
 FOREIGN KEY (village_id) REFERENCES village_tenants(id),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS warga_village_config_versions (
 village_id CHAR(36) PRIMARY KEY, source_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY (village_id) REFERENCES village_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

