<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Sync_model extends CI_Model
{
    private $catalog_schema_ready = false;
    private $resident_schema_ready = false;
    private $official_document_schema_ready = false;

    public function document_for_installation(array $installation, $documentId)
    {
        if (getenv('API_DEMO_MODE') === '1' || empty($installation['village_id'])) return NULL;

        return $this->db
            ->select('d.id, d.original_name, d.storage_path, d.mime_type, d.file_size, d.request_id')
            ->from('request_documents d')
            ->join('service_requests r', 'r.id=d.request_id')
            ->where(array(
                'd.id' => (string) $documentId,
                'r.village_id' => (string) $installation['village_id']
            ))
            ->limit(1)
            ->get()
            ->row_array();
    }

    public function pull(array $installation, $limit = 50)
    {
        $limit = max(1, min(100, (int) $limit));
        if (getenv('API_DEMO_MODE') === '1') return array(
            array('id' => 'demo-sync-0001', 'aggregate_type' => 'service_request', 'aggregate_id' => 'demo-request-000000000000000000000000000003', 'operation' => 'upsert', 'payload' => array('request_code' => 'SDW-2026-0003', 'status' => 'submitted', 'service_slug' => 'usaha', 'citizen_name' => 'Mabel Wenda'), 'attempts' => 1)
        );
        $this->db->where('expires_at <', date('Y-m-d H:i:s'))->delete('api_request_nonces');
        $this->db->where('village_id', $installation['village_id'])->where('direction', 'cloud_to_local');
        $this->db->group_start()->where('status', 'pending')->or_group_start()->where('status', 'processing')->where('updated_at <', date('Y-m-d H:i:s', time() - 600))->group_end()->group_end();
        $rows = $this->db->order_by('available_at', 'ASC')->order_by('created_at', 'ASC')->limit($limit)->get('sync_messages')->result_array();
        $result = array();
        foreach ($rows as $row) {
            $this->db->set('attempts', 'attempts + 1', FALSE)->set('status', 'processing')->set('installation_id', $installation['id'])->where(array('id' => $row['id'], 'village_id' => $installation['village_id'], 'status' => $row['status']));
            if ($row['status'] === 'processing') $this->db->where('updated_at <', date('Y-m-d H:i:s', time() - 600));
            $this->db->update('sync_messages');
            if ($this->db->affected_rows() < 1) continue;
            $payload = json_decode((string) $row['payload_json'], TRUE);
            if (!is_array($payload)) $payload = array();
            if ((string) $row['aggregate_type'] === 'service_request'
                && (string) $row['operation'] === 'upsert'
                && empty($payload['citizen_local_key'])) {
                $localKey = $this->request_citizen_local_key(
                    (string) $row['aggregate_id'],
                    (string) $installation['village_id']
                );
                if ($localKey !== '') $payload['citizen_local_key'] = $localKey;
            }
            $result[] = array('id' => $row['id'], 'aggregate_type' => $row['aggregate_type'], 'aggregate_id' => $row['aggregate_id'], 'operation' => $row['operation'], 'payload' => $payload, 'attempts' => ((int) $row['attempts']) + 1);
        }
        return $result;
    }

    private function request_citizen_local_key($requestId, $villageId)
    {
        if (!$this->db->table_exists('citizen_profiles')
            || !$this->db->field_exists('local_citizen_key', 'citizen_profiles')) return '';
        $row = $this->db
            ->select('cp.local_citizen_key')
            ->from('service_requests r')
            ->join('citizen_profiles cp', 'cp.user_id=r.citizen_user_id AND cp.village_id=r.village_id')
            ->where(array('r.id' => $requestId, 'r.village_id' => $villageId, 'cp.verification_status' => 'verified'))
            ->limit(1)
            ->get()
            ->row_array();
        return $row && !empty($row['local_citizen_key'])
            ? substr(trim((string) $row['local_citizen_key']), 0, 120) : '';
    }

    public function acknowledge(array $installation, array $messages)
    {
        if (getenv('API_DEMO_MODE') === '1') return count($messages);
        $processed = 0;
        foreach ($messages as $message) {
            if (!is_array($message) || !preg_match('/^[A-Za-z0-9._:-]{3,180}$/', (string) ($message['id'] ?? ''))) continue;
            $state = isset($message['status']) && $message['status'] === 'failed' ? 'failed' : 'processed';
            $error = isset($message['error']) ? substr(trim((string) $message['error']), 0, 1000) : NULL;
            $this->db->where(array('id' => (string) $message['id'], 'village_id' => $installation['village_id'], 'installation_id' => $installation['id']))->where_in('status', array('pending', 'processing'))->update('sync_messages', array('status' => $state, 'processed_at' => date('Y-m-d H:i:s'), 'last_error' => $error));
            $processed += $this->db->affected_rows() > 0 ? 1 : 0;
        }
        return $processed;
    }

    public function enqueue(array $installation, array $messages)
    {
        if (getenv('API_DEMO_MODE') === '1') {
            return array('accepted' => count($messages), 'rejected' => 0, 'results' => array());
        }
        $this->ensure_catalog_schema();
        $this->ensure_resident_schema();

        $accepted = 0;
        $rejected = 0;
        $results = array();

        foreach ($messages as $message) {
            if (!is_array($message)) {
                $rejected++;
                continue;
            }

            $idempotency = trim((string) ($message['idempotency_key'] ?? ''));
            $aggregateType = trim((string) ($message['aggregate_type'] ?? ''));
            $aggregateId = trim((string) ($message['aggregate_id'] ?? ''));
            $operation = trim((string) ($message['operation'] ?? ''));
            $payload = isset($message['payload']) && is_array($message['payload']) ? $message['payload'] : NULL;

            if (!preg_match('/^[A-Za-z0-9._:-]{8,180}$/', $idempotency)
                || !preg_match('/^[A-Za-z0-9._:-]{2,80}$/', $aggregateType)
                || $aggregateId === ''
                || strlen($aggregateId) > 120
                || !preg_match('/^[A-Za-z0-9._:-]{2,40}$/', $operation)
                || $payload === NULL
            ) {
                $rejected++;
                continue;
            }

            $existing = $this->db->select('id, village_id, direction, aggregate_type, aggregate_id, operation')
                ->where('idempotency_key', $idempotency)->get('sync_messages')->row_array();
            if ($existing) {
                if ((string) $existing['village_id'] !== (string) $installation['village_id']
                    || $existing['direction'] !== 'local_to_cloud'
                    || $existing['aggregate_type'] !== $aggregateType
                    || $existing['aggregate_id'] !== $aggregateId || $existing['operation'] !== $operation) {
                    $rejected++;
                    $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Identitas pesan sinkronisasi bertentangan.');
                    continue;
                }
                $accepted++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'duplicate');
                continue;
            }

            $this->db->trans_begin();
            $messageId = api_uuid();
            // Directory messages contain raw identity values only while they
            // are being validated. Keep the sync history privacy-minimized.
            $storedPayload = ($aggregateType === 'resident_directory' && $operation === 'snapshot')
                ? $this->redact_resident_payload($payload) : $payload;
            $inserted = $this->db->insert('sync_messages', array(
                'id' => $messageId,
                'village_id' => $installation['village_id'],
                'installation_id' => $installation['id'],
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'direction' => 'local_to_cloud',
                'operation' => $operation,
                'payload_json' => json_encode($storedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'idempotency_key' => $idempotency
            ));

            if (!$inserted) {
                $this->db->trans_rollback();
                $rejected++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Pesan duplikat atau belum dapat disimpan.');
                continue;
            }

            $apply = array('success' => FALSE, 'message' => 'Jenis perubahan sinkronisasi tidak didukung.');
            if ($aggregateType === 'service_request' && $operation === 'status_update') {
                $apply = $this->apply_local_status_update($installation, $aggregateId, $payload);
            } elseif ($aggregateType === 'service_catalog' && $operation === 'upsert') {
                $apply = $this->apply_service_catalog($installation, $payload);
            } elseif ($aggregateType === 'resident_directory' && $operation === 'snapshot') {
                $apply = $this->apply_resident_directory_snapshot($installation, $payload);
            }

            if (empty($apply['success']) || !$this->db->trans_status()) {
                $this->db->trans_rollback();
                $rejected++;
                $results[] = array(
                    'idempotency_key' => $idempotency,
                    'status' => 'failed',
                    'message' => isset($apply['message']) ? $apply['message'] : 'Perubahan belum dapat diterapkan.'
                );
                continue;
            }

            $this->db->where('id', $messageId)->update('sync_messages', array(
                'status' => 'processed',
                'processed_at' => date('Y-m-d H:i:s')
            ));
            if (!$this->db->trans_status()) {
                $this->db->trans_rollback();
                $rejected++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Riwayat sinkronisasi belum dapat disimpan.');
                continue;
            }

            $this->db->trans_commit();
            $accepted++;
            $results[] = array(
                'idempotency_key' => $idempotency,
                'status' => 'accepted',
                'message' => isset($apply['message']) ? $apply['message'] : 'Perubahan diterapkan.'
            );
        }

        return array('accepted' => $accepted, 'rejected' => $rejected, 'results' => $results);
    }

    private function ensure_catalog_schema()
    {
        if ($this->catalog_schema_ready) {
            return;
        }
        if ($this->db->table_exists('sync_messages')) {
            $column = $this->db->query("SHOW COLUMNS FROM `sync_messages` LIKE 'aggregate_id'");
            $column = $column ? $column->row_array() : array();
            if (isset($column['Type']) && preg_match('/^(?:var)?char\((\d+)\)/i', $column['Type'], $length)
                && (int) $length[1] < 120) {
                $this->db->query('ALTER TABLE `sync_messages` MODIFY `aggregate_id` VARCHAR(120) NOT NULL');
            }
        }
        if (!$this->db->table_exists('village_service_catalog')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `village_service_catalog` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `village_id` CHAR(36) NOT NULL,
                `service_key` VARCHAR(80) NOT NULL,
                `name` VARCHAR(180) NOT NULL,
                `short_name` VARCHAR(100) NOT NULL,
                `icon` VARCHAR(80) NOT NULL DEFAULT 'fa-file-alt',
                `description` VARCHAR(1000) DEFAULT NULL,
                `requirements_json` LONGTEXT NULL,
                `form_schema_json` LONGTEXT NULL,
                `template_key` VARCHAR(120) DEFAULT NULL,
                `schema_version` INT UNSIGNED NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `source_updated_at` DATETIME NULL,
                `published_at` DATETIME NULL,
                `source_hash` CHAR(64) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_village_service_key` (`village_id`, `service_key`),
                KEY `idx_village_service_active` (`village_id`, `is_active`, `sort_order`),
                CONSTRAINT `fk_village_service_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $this->ensure_field('village_service_catalog', 'description', "ALTER TABLE `village_service_catalog` ADD `description` VARCHAR(1000) DEFAULT NULL");
            $this->ensure_field('village_service_catalog', 'requirements_json', "ALTER TABLE `village_service_catalog` ADD `requirements_json` LONGTEXT NULL");
            $this->ensure_field('village_service_catalog', 'form_schema_json', "ALTER TABLE `village_service_catalog` ADD `form_schema_json` LONGTEXT NULL");
            $this->ensure_field('village_service_catalog', 'template_key', "ALTER TABLE `village_service_catalog` ADD `template_key` VARCHAR(120) DEFAULT NULL");
            $this->ensure_field('village_service_catalog', 'schema_version', "ALTER TABLE `village_service_catalog` ADD `schema_version` INT UNSIGNED NOT NULL DEFAULT 1");
            $this->ensure_field('village_service_catalog', 'sort_order', "ALTER TABLE `village_service_catalog` ADD `sort_order` INT NOT NULL DEFAULT 0");
            $this->ensure_field('village_service_catalog', 'is_active', "ALTER TABLE `village_service_catalog` ADD `is_active` TINYINT(1) NOT NULL DEFAULT 1");
            $this->ensure_field('village_service_catalog', 'source_updated_at', "ALTER TABLE `village_service_catalog` ADD `source_updated_at` DATETIME NULL");
            $this->ensure_field('village_service_catalog', 'published_at', "ALTER TABLE `village_service_catalog` ADD `published_at` DATETIME NULL");
            $this->ensure_field('village_service_catalog', 'source_hash', "ALTER TABLE `village_service_catalog` ADD `source_hash` CHAR(64) DEFAULT NULL");
        }
        if ($this->db->table_exists('service_requests')) {
            $this->ensure_field('service_requests', 'catalog_service_id', "ALTER TABLE `service_requests` ADD `catalog_service_id` BIGINT UNSIGNED NULL");
            $this->ensure_field('service_requests', 'form_schema_version', "ALTER TABLE `service_requests` ADD `form_schema_version` INT UNSIGNED NULL");
            $this->ensure_index('service_requests', 'idx_requests_catalog', 'KEY `idx_requests_catalog` (`catalog_service_id`)');
        }
        if ($this->db->table_exists('request_documents')) {
            $this->ensure_field('request_documents', 'field_key', "ALTER TABLE `request_documents` ADD `field_key` VARCHAR(100) NULL");
            $this->ensure_index('request_documents', 'idx_request_documents_field', 'KEY `idx_request_documents_field` (`request_id`, `field_key`)');
        }
        $this->catalog_schema_ready = true;
    }

    /**
     * Prepare the privacy-minimized resident directory used to validate new
     * citizen accounts. This is lazy as well as covered by migration 007 so
     * an already deployed API can roll forward without a service outage.
     */
    public function ensure_resident_schema()
    {
        if ($this->resident_schema_ready) {
            return $this->resident_schema_ready;
        }

        if ($this->db->table_exists('citizen_profiles')) {
            $this->ensure_field('citizen_profiles', 'local_citizen_key', "ALTER TABLE `citizen_profiles` ADD `local_citizen_key` VARCHAR(120) DEFAULT NULL");
            $this->ensure_field('citizen_profiles', 'name_hash', "ALTER TABLE `citizen_profiles` ADD `name_hash` CHAR(64) DEFAULT NULL");
            $this->ensure_index('citizen_profiles', 'uniq_citizen_source', 'UNIQUE KEY `uniq_citizen_source` (`village_id`, `local_citizen_key`)');
        }

        if (!$this->db->table_exists('village_resident_directory')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `village_resident_directory` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `village_id` CHAR(36) NOT NULL,
                `local_citizen_key` VARCHAR(120) NOT NULL,
                `nik_hash` CHAR(64) NOT NULL,
                `kk_hash` CHAR(64) NOT NULL,
                `name_hash` CHAR(64) NOT NULL,
                `display_name` VARCHAR(160) NOT NULL,
                `birth_date` DATE DEFAULT NULL,
                `gender` VARCHAR(20) DEFAULT NULL,
                `snapshot_id` CHAR(64) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `last_seen_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resident_source` (`village_id`, `local_citizen_key`),
                UNIQUE KEY `uniq_resident_nik` (`village_id`, `nik_hash`),
                KEY `idx_resident_match` (`village_id`, `nik_hash`, `kk_hash`, `status`),
                KEY `idx_resident_snapshot` (`village_id`, `snapshot_id`, `status`),
                CONSTRAINT `fk_resident_directory_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $this->ensure_field('village_resident_directory', 'local_citizen_key', "ALTER TABLE `village_resident_directory` ADD `local_citizen_key` VARCHAR(120) NOT NULL DEFAULT ''");
            $this->ensure_field('village_resident_directory', 'nik_hash', "ALTER TABLE `village_resident_directory` ADD `nik_hash` CHAR(64) NOT NULL DEFAULT ''");
            $this->ensure_field('village_resident_directory', 'kk_hash', "ALTER TABLE `village_resident_directory` ADD `kk_hash` CHAR(64) NOT NULL DEFAULT ''");
            $this->ensure_field('village_resident_directory', 'name_hash', "ALTER TABLE `village_resident_directory` ADD `name_hash` CHAR(64) NOT NULL DEFAULT ''");
            $this->ensure_field('village_resident_directory', 'display_name', "ALTER TABLE `village_resident_directory` ADD `display_name` VARCHAR(160) NOT NULL DEFAULT ''");
            $this->ensure_field('village_resident_directory', 'birth_date', "ALTER TABLE `village_resident_directory` ADD `birth_date` DATE DEFAULT NULL");
            $this->ensure_field('village_resident_directory', 'gender', "ALTER TABLE `village_resident_directory` ADD `gender` VARCHAR(20) DEFAULT NULL");
            $this->ensure_field('village_resident_directory', 'snapshot_id', "ALTER TABLE `village_resident_directory` ADD `snapshot_id` CHAR(64) NOT NULL DEFAULT ''");
            $this->ensure_field('village_resident_directory', 'status', "ALTER TABLE `village_resident_directory` ADD `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
            $this->ensure_field('village_resident_directory', 'last_seen_at', "ALTER TABLE `village_resident_directory` ADD `last_seen_at` DATETIME DEFAULT NULL");
            $this->ensure_field('village_resident_directory', 'created_at', "ALTER TABLE `village_resident_directory` ADD `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $this->ensure_field('village_resident_directory', 'updated_at', "ALTER TABLE `village_resident_directory` ADD `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        $this->ensure_index('village_resident_directory', 'uniq_resident_source', 'UNIQUE KEY `uniq_resident_source` (`village_id`, `local_citizen_key`)');
        $this->ensure_index('village_resident_directory', 'uniq_resident_nik', 'UNIQUE KEY `uniq_resident_nik` (`village_id`, `nik_hash`)');
        $this->ensure_index('village_resident_directory', 'idx_resident_match', 'KEY `idx_resident_match` (`village_id`, `nik_hash`, `kk_hash`, `status`)');
        $this->ensure_index('village_resident_directory', 'idx_resident_snapshot', 'KEY `idx_resident_snapshot` (`village_id`, `snapshot_id`, `status`)');

        if (!$this->db->table_exists('village_resident_snapshots')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `village_resident_snapshots` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `village_id` CHAR(36) NOT NULL,
                `snapshot_id` CHAR(64) NOT NULL,
                `snapshot_created_at` DATETIME NOT NULL,
                `batch_total` INT UNSIGNED NOT NULL DEFAULT 1,
                `finalized` TINYINT(1) NOT NULL DEFAULT 0,
                `finalized_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resident_snapshot` (`village_id`, `snapshot_id`),
                KEY `idx_resident_snapshot_latest` (`village_id`, `snapshot_created_at`),
                CONSTRAINT `fk_resident_snapshot_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$this->db->table_exists('village_resident_snapshot_batches')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `village_resident_snapshot_batches` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `village_id` CHAR(36) NOT NULL,
                `snapshot_id` CHAR(64) NOT NULL,
                `batch_index` INT UNSIGNED NOT NULL,
                `batch_total` INT UNSIGNED NOT NULL,
                `resident_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resident_snapshot_batch` (`village_id`, `snapshot_id`, `batch_index`),
                KEY `idx_resident_batch_snapshot` (`village_id`, `snapshot_id`),
                CONSTRAINT `fk_resident_batch_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$this->db->table_exists('resident_verification_attempts')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `resident_verification_attempts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ip_hash` CHAR(64) NOT NULL,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_resident_attempt_ip` (`ip_hash`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $this->resident_schema_ready = $this->db->table_exists('village_resident_directory')
            && $this->db->table_exists('village_resident_snapshots')
            && $this->db->table_exists('village_resident_snapshot_batches')
            && $this->db->table_exists('resident_verification_attempts');
        return $this->resident_schema_ready;
    }

    public function ensure_official_document_schema()
    {
        if ($this->official_document_schema_ready
            || !$this->db->table_exists('service_requests')) {
            return $this->official_document_schema_ready;
        }
        $this->ensure_field('service_requests', 'document_sha256', "ALTER TABLE `service_requests` ADD `document_sha256` CHAR(64) NULL AFTER `document_path`");
        $this->ensure_field('service_requests', 'document_size', "ALTER TABLE `service_requests` ADD `document_size` BIGINT UNSIGNED NULL AFTER `document_sha256`");
        $this->official_document_schema_ready = $this->db->field_exists('document_sha256', 'service_requests')
            && $this->db->field_exists('document_size', 'service_requests');
        return $this->official_document_schema_ready;
    }

    public function publish_official_document(array $installation, $requestId, $reference, $path, $sha256, $size, $actorName)
    {
        if (!$this->ensure_official_document_schema()) {
            return array('success' => FALSE, 'message' => 'Struktur dokumen resmi belum siap.');
        }
        $request = $this->db
            ->select('id, citizen_user_id, status, document_path, document_sha256')
            ->where(array('id' => (string) $requestId, 'village_id' => (string) $installation['village_id']))
            ->limit(1)
            ->get('service_requests')
            ->row_array();
        if (!$request) return array('success' => FALSE, 'message' => 'Permohonan tidak ditemukan pada desa ini.');

        if ((string) $request['status'] === 'issued') {
            if (!empty($request['document_sha256']) && hash_equals((string) $request['document_sha256'], (string) $sha256)) {
                return array('success' => TRUE, 'duplicate' => TRUE, 'message' => 'Dokumen resmi ini sudah diterbitkan.');
            }
            return array('success' => FALSE, 'message' => 'Permohonan sudah memiliki dokumen resmi yang berbeda.');
        }
        if ((string) $request['status'] !== 'approved') {
            return array('success' => FALSE, 'message' => 'Dokumen hanya dapat diterbitkan setelah permohonan disetujui.');
        }

        $now = date('Y-m-d H:i:s');
        if (!$this->db->trans_begin()) {
            return array('success' => FALSE, 'message' => 'Transaksi penerbitan belum dapat dimulai.');
        }
        $this->db
            ->where(array('id' => (string) $requestId, 'village_id' => (string) $installation['village_id'], 'status' => 'approved'))
            ->update('service_requests', array(
                'status' => 'issued',
                'local_reference' => substr(trim((string) $reference), 0, 160),
                'document_path' => (string) $path,
                'document_sha256' => (string) $sha256,
                'document_size' => (int) $size,
                'local_sync_status' => 'synced',
                'local_synced_at' => $now
            ));
        if ($this->db->affected_rows() !== 1) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Status permohonan berubah karena diproses pengguna lain.');
        }
        $note = 'Dokumen resmi telah diterbitkan oleh ' . ($actorName !== '' ? $actorName : 'petugas desa') . '.';
        $this->db->insert('request_status_history', array(
            'request_id' => (string) $requestId,
            'from_status' => 'approved',
            'to_status' => 'issued',
            'note' => $note,
            'actor_id' => NULL,
            'occurred_at' => $now
        ));
        $this->db->insert('notifications', array(
            'id' => api_uuid(),
            'user_id' => (int) $request['citizen_user_id'],
            'request_id' => (string) $requestId,
            'title' => 'Surat telah diterbitkan',
            'message' => 'Dokumen resmi sudah tersedia dan dapat diunduh dari aplikasi warga.'
        ));
        if (!$this->db->trans_status()) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Penerbitan dokumen belum dapat disimpan.');
        }
        $this->db->trans_commit();
        return array('success' => TRUE, 'duplicate' => FALSE, 'message' => 'Dokumen resmi berhasil diterbitkan.');
    }

    private function apply_service_catalog(array $installation, array $payload)
    {
        $villageId = trim((string) (isset($installation['village_id']) ? $installation['village_id'] : ''));
        $services = isset($payload['services']) && is_array($payload['services']) ? $payload['services'] : NULL;
        if ($villageId === '' || $services === NULL || count($services) > 200) {
            return array('success' => FALSE, 'message' => 'Katalog layanan tidak valid.');
        }

        $normalised = array();
        $keys = array();
        foreach ($services as $index => $service) {
            if (!is_array($service)) {
                return array('success' => FALSE, 'message' => 'Layanan katalog nomor ' . ((int) $index + 1) . ' belum valid.');
            }
            $key = strtolower(trim((string) (isset($service['service_key']) ? $service['service_key'] : '')));
            if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $key) || isset($keys[$key])) {
                return array('success' => FALSE, 'message' => 'Kunci layanan katalog tidak valid atau duplikat.');
            }
            $name = $this->catalog_text(isset($service['name']) ? $service['name'] : '', 180);
            $shortName = $this->catalog_text(isset($service['short_name']) ? $service['short_name'] : '', 100);
            if ($name === '' || $shortName === '') {
                return array('success' => FALSE, 'message' => 'Nama layanan katalog wajib diisi.');
            }
            $schemaError = NULL;
            $schema = $this->normalise_catalog_schema(isset($service['form_schema']) ? $service['form_schema'] : array(), $schemaError);
            if ($schema === FALSE) {
                return array('success' => FALSE, 'message' => $schemaError ?: 'Form layanan katalog belum valid.');
            }
            $requirements = array();
            if (isset($service['requirements']) && is_array($service['requirements'])) {
                foreach (array_slice($service['requirements'], 0, 50) as $requirement) {
                    $requirement = $this->catalog_text($requirement, 180);
                    if ($requirement !== '') $requirements[] = $requirement;
                }
            }
            $icon = trim((string) (isset($service['icon']) ? $service['icon'] : 'fa-file-alt'));
            if (!preg_match('/^[A-Za-z0-9 _-]{1,80}$/', $icon)) $icon = 'fa-file-alt';
            $templateKey = strtolower(trim((string) (isset($service['template_key']) ? $service['template_key'] : $key)));
            $templateKey = preg_replace('/[^a-z0-9_-]+/', '-', $templateKey);
            $templateKey = trim(substr($templateKey, 0, 120), '-_');
            if ($templateKey === '') $templateKey = $key;
            $sourceUpdated = $this->catalog_datetime(isset($service['source_updated_at']) ? $service['source_updated_at'] : '');
            $sourceHash = strtolower(trim((string) (isset($service['source_hash']) ? $service['source_hash'] : '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $sourceHash)) $sourceHash = NULL;
            $normalised[] = array(
                'service_key' => $key,
                'name' => $name,
                'short_name' => $shortName,
                'icon' => $icon,
                'description' => $this->catalog_text(isset($service['description']) ? $service['description'] : '', 1000),
                'requirements_json' => json_encode($requirements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'form_schema_json' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'template_key' => $templateKey,
                'schema_version' => (int) $schema['version'],
                'sort_order' => count($normalised),
                'source_updated_at' => $sourceUpdated,
                'source_hash' => $sourceHash
            );
            $keys[$key] = TRUE;
        }

        foreach ($normalised as $service) {
            $legacy = $this->db->where('slug', $service['service_key'])->limit(1)->get('service_types')->row_array();
            if (!$legacy) {
                $inserted = $this->db->insert('service_types', array(
                    'slug' => $service['service_key'],
                    'name' => $service['name'],
                    'short_name' => $service['short_name'],
                    'icon' => $service['icon'],
                    'description' => $this->catalog_text($service['description'], 500),
                    'requirements_json' => $service['requirements_json'],
                    'template_key' => $this->catalog_text($service['template_key'], 100),
                    'sort_order' => $service['sort_order'],
                    'is_active' => 1
                ));
                if (!$inserted) return array('success' => FALSE, 'message' => 'Relasi layanan lama belum dapat dibuat.');
                $legacy = array('id' => $this->db->insert_id());
            }
            $row = $this->db->where(array('village_id' => $villageId, 'service_key' => $service['service_key']))->limit(1)->get('village_service_catalog')->row_array();
            $data = array(
                'name' => $service['name'],
                'short_name' => $service['short_name'],
                'icon' => $service['icon'],
                'description' => $service['description'] !== '' ? $service['description'] : NULL,
                'requirements_json' => $service['requirements_json'],
                'form_schema_json' => $service['form_schema_json'],
                'template_key' => $service['template_key'],
                'schema_version' => $service['schema_version'],
                'sort_order' => $service['sort_order'],
                'is_active' => 1,
                'source_updated_at' => $service['source_updated_at'],
                'published_at' => date('Y-m-d H:i:s'),
                'source_hash' => $service['source_hash']
            );
            if ($row) {
                $ok = $this->db->where('id', (int) $row['id'])->update('village_service_catalog', $data);
            } else {
                $data['village_id'] = $villageId;
                $data['service_key'] = $service['service_key'];
                $ok = $this->db->insert('village_service_catalog', $data);
            }
            if (!$ok) return array('success' => FALSE, 'message' => 'Katalog layanan belum dapat disimpan.');
        }
        $this->db->where('village_id', $villageId);
        if (!empty($keys)) $this->db->where_not_in('service_key', array_keys($keys));
        $this->db->update('village_service_catalog', array('is_active' => 0));
        if (!$this->db->trans_status()) return array('success' => FALSE, 'message' => 'Status katalog layanan belum dapat diperbarui.');
        return array('success' => TRUE, 'message' => 'Katalog Layanan Warga tersimpan.', 'service_count' => count($normalised));
    }

    private function redact_resident_payload(array $payload)
    {
        $residents = isset($payload['residents']) && is_array($payload['residents']) ? $payload['residents'] : array();
        return array(
            'directory_version' => 1,
            'snapshot_id' => strtolower(trim((string) (isset($payload['snapshot_id']) ? $payload['snapshot_id'] : ''))),
            'snapshot_created_at' => $this->catalog_datetime(isset($payload['snapshot_created_at']) ? $payload['snapshot_created_at'] : '') ?: date('Y-m-d H:i:s'),
            'batch_index' => (int) (isset($payload['batch_index']) ? $payload['batch_index'] : 0),
            'batch_total' => (int) (isset($payload['batch_total']) ? $payload['batch_total'] : 0),
            'resident_count' => count($residents)
        );
    }

    /**
     * Apply one signed resident-directory batch. Raw identifiers are hashed
     * immediately and never copied into sync_messages or the directory table.
     */
    private function apply_resident_directory_snapshot(array $installation, array $payload)
    {
        if (!$this->resident_schema_ready) {
            return array('success' => FALSE, 'message' => 'Skema direktori penduduk belum siap.');
        }

        $villageId = trim((string) (isset($installation['village_id']) ? $installation['village_id'] : ''));
        $snapshotId = strtolower(trim((string) (isset($payload['snapshot_id']) ? $payload['snapshot_id'] : '')));
        $snapshotCreated = $this->catalog_datetime(isset($payload['snapshot_created_at']) ? $payload['snapshot_created_at'] : '');
        $batchIndex = (int) (isset($payload['batch_index']) ? $payload['batch_index'] : 0);
        $batchTotal = (int) (isset($payload['batch_total']) ? $payload['batch_total'] : 0);
        $residents = isset($payload['residents']) && is_array($payload['residents']) ? $payload['residents'] : NULL;

        if ($villageId === '' || !preg_match('/^[a-f0-9]{64}$/', $snapshotId)
            || $snapshotCreated === NULL || $batchTotal < 1 || $batchTotal > 500
            || $batchIndex < 1 || $batchIndex > $batchTotal || $residents === NULL
            || count($residents) > 250) {
            return array('success' => FALSE, 'message' => 'Batch direktori penduduk tidak valid.');
        }

        // A retry from an older local snapshot must never reactivate residents
        // that a newer snapshot has already removed.
        $latest = $this->db->where('village_id', $villageId)
            ->order_by('snapshot_created_at', 'DESC')->order_by('id', 'DESC')
            ->limit(1)->get('village_resident_snapshots')->row_array();
        if ($latest && (string) $latest['snapshot_id'] !== $snapshotId) {
            $latestTime = strtotime((string) $latest['snapshot_created_at']);
            $incomingTime = strtotime($snapshotCreated);
            if ($incomingTime < $latestTime
                || ($incomingTime === $latestTime && strcmp($snapshotId, (string) $latest['snapshot_id']) < 0)) {
                return array('success' => TRUE, 'message' => 'Batch direktori lama diabaikan.', 'stale' => TRUE);
            }
        }

        $normalised = array();
        $seenSource = array();
        $seenNik = array();
        foreach ($residents as $resident) {
            if (!is_array($resident)) {
                return array('success' => FALSE, 'message' => 'Data penduduk pada batch tidak valid.');
            }
            $sourceKey = $this->resident_text(isset($resident['source_key']) ? $resident['source_key'] : '', 120);
            $nik = $this->resident_digits(isset($resident['nik']) ? $resident['nik'] : '');
            $kk = $this->resident_digits(isset($resident['kk']) ? $resident['kk'] : '');
            $name = $this->resident_text(isset($resident['name']) ? $resident['name'] : '', 160);
            if (!preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $sourceKey)
                || !preg_match('/^[0-9]{16}$/', $nik)
                || !preg_match('/^[0-9]{16}$/', $kk)
                || $name === '' || isset($seenSource[$sourceKey]) || isset($seenNik[$nik])) {
                return array('success' => FALSE, 'message' => 'Data identitas penduduk pada batch tidak lengkap atau duplikat.');
            }
            $birthDate = trim((string) (isset($resident['birth_date']) ? $resident['birth_date'] : ''));
            $parsedBirthDate = $birthDate !== '' ? DateTime::createFromFormat('!Y-m-d', $birthDate) : FALSE;
            if (!$parsedBirthDate || $parsedBirthDate->format('Y-m-d') !== $birthDate) {
                $birthDate = NULL;
            }
            $seenSource[$sourceKey] = TRUE;
            $seenNik[$nik] = TRUE;
            $normalised[] = array(
                'source_key' => $sourceKey,
                'nik_hash' => $this->resident_hash($nik),
                'kk_hash' => $this->resident_hash($kk),
                'name_hash' => $this->resident_hash($this->resident_name($name)),
                'display_name' => $name,
                'birth_date' => $birthDate,
                'gender' => $this->resident_text(isset($resident['gender']) ? $resident['gender'] : '', 20)
            );
        }

        foreach ($normalised as $resident) {
            $sameNik = $this->db->select('local_citizen_key')->where(array(
                'village_id' => $villageId,
                'nik_hash' => $resident['nik_hash']
            ))->limit(1)->get('village_resident_directory')->row_array();
            if ($sameNik && (string) $sameNik['local_citizen_key'] !== $resident['source_key']) {
                return array('success' => FALSE, 'message' => 'NIK ganda ditemukan pada data penduduk desa.');
            }
        }

        $now = date('Y-m-d H:i:s');
        foreach ($normalised as $resident) {
            $existing = $this->db->where(array(
                'village_id' => $villageId,
                'local_citizen_key' => $resident['source_key']
            ))->limit(1)->get('village_resident_directory')->row_array();
            $data = array(
                'nik_hash' => $resident['nik_hash'],
                'kk_hash' => $resident['kk_hash'],
                'name_hash' => $resident['name_hash'],
                'display_name' => $resident['display_name'],
                'birth_date' => $resident['birth_date'],
                'gender' => $resident['gender'] !== '' ? $resident['gender'] : NULL,
                'snapshot_id' => $snapshotId,
                'status' => 'active',
                'last_seen_at' => $now,
                'updated_at' => $now
            );
            if ($existing) {
                $identityChanged = (string) $existing['nik_hash'] !== $resident['nik_hash']
                    || (string) $existing['kk_hash'] !== $resident['kk_hash']
                    || (string) $existing['name_hash'] !== $resident['name_hash'];
                if ($identityChanged && $this->db->table_exists('citizen_profiles')) {
                    $this->db->where(array('village_id' => $villageId, 'local_citizen_key' => $resident['source_key']))
                        ->where('verification_status', 'verified')
                        ->update('citizen_profiles', array('verification_status' => 'revalidation_required', 'updated_at' => $now));
                }
                $ok = $this->db->where('id', (int) $existing['id'])->update('village_resident_directory', $data);
            } else {
                $data['village_id'] = $villageId;
                $data['local_citizen_key'] = $resident['source_key'];
                $ok = $this->db->insert('village_resident_directory', $data);
            }
            if (!$ok) return array('success' => FALSE, 'message' => 'Direktori penduduk belum dapat disimpan.');
        }

        $snapshot = $this->db->where(array('village_id' => $villageId, 'snapshot_id' => $snapshotId))
            ->limit(1)->get('village_resident_snapshots')->row_array();
        if (!$snapshot) {
            $ok = $this->db->insert('village_resident_snapshots', array(
                'village_id' => $villageId,
                'snapshot_id' => $snapshotId,
                'snapshot_created_at' => $snapshotCreated,
                'batch_total' => $batchTotal,
                'finalized' => 0,
                'created_at' => $now,
                'updated_at' => $now
            ));
            if (!$ok) return array('success' => FALSE, 'message' => 'Riwayat direktori penduduk belum dapat dibuat.');
        } elseif ((int) $snapshot['batch_total'] !== $batchTotal) {
            return array('success' => FALSE, 'message' => 'Jumlah batch direktori penduduk tidak konsisten.');
        }

        $batch = $this->db->where(array(
            'village_id' => $villageId,
            'snapshot_id' => $snapshotId,
            'batch_index' => $batchIndex
        ))->limit(1)->get('village_resident_snapshot_batches')->row_array();
        if (!$batch) {
            $ok = $this->db->insert('village_resident_snapshot_batches', array(
                'village_id' => $villageId,
                'snapshot_id' => $snapshotId,
                'batch_index' => $batchIndex,
                'batch_total' => $batchTotal,
                'resident_count' => count($normalised),
                'created_at' => $now
            ));
            if (!$ok) return array('success' => FALSE, 'message' => 'Batch direktori penduduk belum dapat dicatat.');
        }

        $received = (int) $this->db->where(array('village_id' => $villageId, 'snapshot_id' => $snapshotId))->count_all_results('village_resident_snapshot_batches');
        $complete = $received >= $batchTotal;
        if ($complete) {
            $latest = $this->db->where('village_id', $villageId)
                ->order_by('snapshot_created_at', 'DESC')->order_by('id', 'DESC')
                ->limit(1)->get('village_resident_snapshots')->row_array();
            if ($latest && (string) $latest['snapshot_id'] === $snapshotId) {
                $this->db->where('village_id', $villageId)->where('snapshot_id !=', $snapshotId)
                    ->update('village_resident_directory', array('status' => 'inactive', 'updated_at' => $now));
                if ($this->db->table_exists('citizen_profiles')) {
                    $stale = $this->db->select('local_citizen_key')->where(array('village_id' => $villageId, 'status' => 'inactive'))
                        ->where('local_citizen_key !=', '')->get('village_resident_directory')->result_array();
                    foreach ($stale as $row) {
                        $this->db->where(array('village_id' => $villageId, 'local_citizen_key' => $row['local_citizen_key']))
                            ->where_in('verification_status', array('verified', 'revalidation_required'))
                            ->update('citizen_profiles', array('verification_status' => 'inactive', 'updated_at' => $now));
                    }
                }
                $this->db->where(array('village_id' => $villageId, 'snapshot_id' => $snapshotId))
                    ->update('village_resident_snapshots', array('finalized' => 1, 'finalized_at' => $now, 'updated_at' => $now));
            }
        }

        return array(
            'success' => TRUE,
            'message' => $complete ? 'Direktori penduduk tersimpan dan diaktifkan.' : 'Batch direktori penduduk tersimpan.',
            'snapshot_complete' => $complete,
            'resident_count' => count($normalised)
        );
    }

    private function apply_local_status_update(array $installation, $aggregateId, array $payload)
    {
        $request = $this->db
            ->where(array('id' => (string) $aggregateId, 'village_id' => $installation['village_id']))
            ->limit(1)
            ->get('service_requests')
            ->row_array();
        if (!$request) {
            return array('success' => FALSE, 'message' => 'Permohonan tidak ditemukan pada desa ini.');
        }

        $fromStatus = trim((string) $request['status']);
        $toStatus = trim((string) (isset($payload['status']) ? $payload['status'] : (isset($payload['to_status']) ? $payload['to_status'] : '')));
        $actorRole = strtolower(trim((string) (isset($payload['actor_role']) ? $payload['actor_role'] : '')));
        $actorRole = str_replace('_', '-', $actorRole);
        $note = trim((string) (isset($payload['note']) ? $payload['note'] : ''));
        $actorName = substr(trim((string) (isset($payload['actor_name']) ? $payload['actor_name'] : 'Petugas Desa')), 0, 160);

        $allowed = FALSE;
        if ($actorRole === 'sekdes') {
            $allowed = $fromStatus === 'submitted' && in_array($toStatus, array('verified', 'revision', 'rejected'), TRUE);
        } elseif (in_array($actorRole, array('kepala-desa', 'kades'), TRUE)) {
            $allowed = $fromStatus === 'verified' && in_array($toStatus, array('approved', 'revision', 'rejected'), TRUE);
        } elseif (in_array($actorRole, array('admin-desa', 'administrator', 'admin-pusat', 'pelayanan-surat'), TRUE)) {
            $allowed = ($fromStatus === 'submitted' && in_array($toStatus, array('verified', 'revision', 'rejected'), TRUE))
                || ($fromStatus === 'verified' && in_array($toStatus, array('approved', 'revision', 'rejected'), TRUE))
                || ($fromStatus === 'approved' && in_array($toStatus, array('revision', 'rejected'), TRUE));
        }

        if (!$allowed) {
            return array('success' => FALSE, 'message' => 'Perubahan status tidak sesuai alur atau peran petugas.');
        }
        if (in_array($toStatus, array('revision', 'rejected'), TRUE) && $note === '') {
            return array('success' => FALSE, 'message' => 'Alasan wajib diisi untuk perbaikan atau penolakan.');
        }
        if ($note === '') {
            $note = 'Status diperbarui melalui SmartDesa lokal.';
        }

        $now = date('Y-m-d H:i:s');
        $this->db
            ->where(array('id' => (string) $aggregateId, 'village_id' => $installation['village_id'], 'status' => $fromStatus))
            ->update('service_requests', array(
                'status' => $toStatus,
                'local_sync_status' => 'synced',
                'local_synced_at' => $now
            ));
        if ($this->db->affected_rows() !== 1) {
            return array('success' => FALSE, 'message' => 'Status permohonan berubah karena diproses pengguna lain.');
        }

        $this->db->insert('request_status_history', array(
            'request_id' => (string) $aggregateId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'actor_id' => NULL,
            'occurred_at' => $now
        ));
        $serviceName = !empty($request['service_type_id']) ? 'Permohonan layanan' : 'Permohonan warga';
        $this->db->insert('notifications', array(
            'id' => api_uuid(),
            'user_id' => (int) $request['citizen_user_id'],
            'request_id' => (string) $aggregateId,
            'title' => $serviceName,
            'message' => $toStatus . ' oleh ' . ($actorName !== '' ? $actorName : 'petugas desa') . '. ' . $note
        ));

        return array('success' => TRUE, 'message' => 'Status permohonan diperbarui menjadi ' . $toStatus . '.');
    }

    private function normalise_catalog_schema($raw, &$error = NULL)
    {
        $error = NULL;
        if (!is_array($raw)) {
            $error = 'Struktur formulir layanan tidak valid.';
            return FALSE;
        }
        $version = isset($raw['version']) ? (int) $raw['version'] : 1;
        if ($version < 1 || $version > 99) {
            $error = 'Versi formulir tidak valid.';
            return FALSE;
        }
        $fields = isset($raw['fields']) && is_array($raw['fields']) ? $raw['fields'] : array();
        if (count($fields) > 30) {
            $error = 'Formulir maksimal memiliki 30 isian.';
            return FALSE;
        }
        $allowed = array('text', 'textarea', 'date', 'select', 'number', 'tel', 'email', 'file');
        $reserved = array('service_type', 'purpose', 'note', 'csrf_token', 'warga_fields', 'warga_files');
        $seen = array();
        $result = array();
        foreach ($fields as $field) {
            if (!is_array($field)) {
                $error = 'Struktur isian formulir tidak valid.';
                return FALSE;
            }
            $key = strtolower(trim((string) (isset($field['key']) ? $field['key'] : '')));
            if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key) || in_array($key, $reserved, TRUE) || isset($seen[$key])) {
                $error = 'Kunci isian formulir tidak valid atau duplikat.';
                return FALSE;
            }
            $label = $this->catalog_text(isset($field['label']) ? $field['label'] : '', 180);
            $type = strtolower(trim((string) (isset($field['type']) ? $field['type'] : 'text')));
            if ($label === '' || !in_array($type, $allowed, TRUE)) {
                $error = 'Label atau tipe isian formulir tidak valid.';
                return FALSE;
            }
            $options = array();
            if ($type === 'select') {
                if (!isset($field['options']) || !is_array($field['options']) || count($field['options']) < 1) {
                    $error = 'Pilihan formulir belum diisi.';
                    return FALSE;
                }
                foreach (array_slice($field['options'], 0, 50) as $option) {
                    if (!is_array($option)) {
                        $error = 'Pilihan formulir tidak valid.';
                        return FALSE;
                    }
                    $value = $this->catalog_text(isset($option['value']) ? $option['value'] : '', 100);
                    $optionLabel = $this->catalog_text(isset($option['label']) ? $option['label'] : $value, 180);
                    if ($value === '' || $optionLabel === '') {
                        $error = 'Pilihan formulir tidak boleh kosong.';
                        return FALSE;
                    }
                    $options[] = array('value' => $value, 'label' => $optionLabel);
                }
                if (empty($options)) {
                    $error = 'Pilihan formulir belum valid.';
                    return FALSE;
                }
            }
            $accept = strtolower(trim((string) (isset($field['accept']) ? $field['accept'] : '')));
            $acceptParts = preg_split('/\s*,\s*/', $accept);
            $acceptedTypes = array('image/jpeg', 'image/png', 'image/*', 'application/pdf', '.jpg', '.jpeg', '.png', '.pdf');
            $cleanAccept = array();
            foreach ($acceptParts as $part) if ($part !== '' && in_array($part, $acceptedTypes, TRUE) && !in_array($part, $cleanAccept, TRUE)) $cleanAccept[] = $part;
            if (empty($cleanAccept)) $cleanAccept = array('image/jpeg', 'image/png', 'application/pdf');
            $seen[$key] = TRUE;
            $result[] = array(
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => !empty($field['required']),
                'options' => $options,
                'help' => $this->catalog_text(isset($field['help']) ? $field['help'] : '', 500),
                'placeholder' => $this->catalog_text(isset($field['placeholder']) ? $field['placeholder'] : '', 180),
                'accept' => $type === 'file' ? implode(',', $cleanAccept) : '',
                'max_length' => $type === 'file' ? 0 : max(1, min(5000, (int) (isset($field['max_length']) ? $field['max_length'] : 500))),
                'max_size_mb' => $type === 'file' ? max(1, min(10, (int) (isset($field['max_size_mb']) ? $field['max_size_mb'] : 5))) : 0,
                'multiple' => $type === 'file' && !empty($field['multiple']),
                'binding' => $this->catalog_key(isset($field['binding']) ? $field['binding'] : '')
            );
        }
        return array('version' => $version, 'fields' => $result);
    }

    private function resident_digits($value)
    {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function resident_name($value)
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function resident_text($value, $maxLength)
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return function_exists('mb_substr') ? mb_substr($value, 0, (int) $maxLength, 'UTF-8') : substr($value, 0, (int) $maxLength);
    }

    private function resident_hash($value)
    {
        return hash_hmac('sha256', (string) $value, (string) getenv('APP_KEY'));
    }

    private function catalog_text($value, $maxLength)
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));
        return function_exists('mb_substr') ? mb_substr($value, 0, (int) $maxLength, 'UTF-8') : substr($value, 0, (int) $maxLength);
    }

    private function catalog_key($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        return trim(substr($value, 0, 120), '-_');
    }

    private function catalog_datetime($value)
    {
        $value = trim((string) $value);
        if ($value === '') return NULL;
        $time = strtotime($value);
        return $time ? date('Y-m-d H:i:s', $time) : NULL;
    }

    private function ensure_field($table, $field, $sql)
    {
        if (!$this->db->field_exists($field, $table)) $this->db->query($sql);
    }

    private function ensure_index($table, $name, $definition)
    {
        $query = $this->db->query('SHOW INDEX FROM `' . $table . '`');
        if ($query) {
            foreach ($query->result_array() as $row) {
                if (isset($row['Key_name']) && (string) $row['Key_name'] === $name) return;
            }
        }
        $this->db->query('ALTER TABLE `' . $table . '` ADD ' . $definition);
    }
}
