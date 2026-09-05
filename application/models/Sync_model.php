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
            array(
                'id' => 'demo-sync-0001',
                'aggregate_type' => 'service_request',
                'aggregate_id' => 'demo-request-000000000000000000000000000003',
                'operation' => 'upsert',
                'event_version' => 1,
                'payload' => array(
                    'request_id' => 'demo-request-000000000000000000000000000003',
                    'request_code' => 'SDW-2026-0003',
                    'event_version' => 1,
                    'status' => 'submitted',
                    'service_slug' => 'usaha',
                    'citizen_name' => 'Mabel Wenda'
                ),
                'attempts' => 1
            )
        );
        $this->db->where('expires_at <', date('Y-m-d H:i:s'))->delete('api_request_nonces');
        $this->db->where('village_id', $installation['village_id'])->where('direction', 'cloud_to_local');
        $this->db->group_start()->where('status', 'pending')->or_group_start()->where('status', 'processing')->where('updated_at <', date('Y-m-d H:i:s', time() - 600))->group_end()->group_end();
        $rows = $this->db->order_by('available_at', 'ASC')->order_by('event_version', 'ASC')->order_by('created_at', 'ASC')->limit($limit)->get('sync_messages')->result_array();
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
            $result[] = array('id' => $row['id'], 'aggregate_type' => $row['aggregate_type'], 'aggregate_id' => $row['aggregate_id'], 'operation' => $row['operation'], 'event_version' => (int) ($row['event_version'] ?? 0), 'payload' => $payload, 'attempts' => ((int) $row['attempts']) + 1);
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
            $versionError = '';
            $eventVersion = $payload === NULL ? 0
                : $this->sync_event_version($aggregateType, $operation, $message, $payload, $versionError);

            if (!preg_match('/^[A-Za-z0-9._:-]{8,180}$/', $idempotency)
                || !preg_match('/^[A-Za-z0-9._:-]{2,80}$/', $aggregateType)
                || $aggregateId === ''
                || strlen($aggregateId) > 120
                || !preg_match('/^[A-Za-z0-9._:-]{2,40}$/', $operation)
                || $payload === NULL
                || $eventVersion < 1
            ) {
                $rejected++;
                if ($idempotency !== '') {
                    $results[] = array(
                        'idempotency_key' => $idempotency,
                        'status' => 'failed',
                        'message' => $versionError !== '' ? $versionError : 'Format pesan sinkronisasi tidak valid.'
                    );
                }
                continue;
            }

            $payloadFingerprint = $this->sync_payload_fingerprint($payload);

            $existing = $this->db->select('id, village_id, installation_id, direction, aggregate_type, aggregate_id, operation, event_version, payload_fingerprint, payload_json')
                ->where('idempotency_key', $idempotency)->get('sync_messages')->row_array();
            if ($existing) {
                if (!$this->sync_message_identity_matches(
                    $existing,
                    $installation,
                    $aggregateType,
                    $aggregateId,
                    $operation,
                    $payload,
                    $eventVersion,
                    $payloadFingerprint
                )) {
                    $rejected++;
                    $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Kunci idempotensi sudah digunakan untuk isi atau versi pesan yang berbeda.');
                    continue;
                }
                $accepted++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'duplicate');
                continue;
            }

            if (!$this->db->trans_begin()) {
                $rejected++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Transaksi sinkronisasi belum dapat dimulai.');
                continue;
            }
            $messageId = api_uuid();
            // Directory messages contain raw identity values only while they
            // are being validated. Keep the sync history privacy-minimized.
            $storedPayload = ($aggregateType === 'resident_directory' && $operation === 'snapshot')
                ? $this->redact_resident_payload($payload) : $payload;
            if ($aggregateType === 'staff_accounts') {
                $storedPayload = array('source_revision' => $payload['source_revision'] ?? '',
                    'snapshot_hash' => $payload['snapshot_hash'] ?? '',
                    'staff_count' => isset($payload['staff']) && is_array($payload['staff']) ? count($payload['staff']) : 0);
            }
            $previousDebug = $this->db->db_debug;
            $this->db->db_debug = FALSE;
            $inserted = $this->db->insert('sync_messages', array(
                'id' => $messageId,
                'village_id' => $installation['village_id'],
                'installation_id' => $installation['id'],
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'direction' => 'local_to_cloud',
                'operation' => $operation,
                'event_version' => $eventVersion,
                'payload_fingerprint' => $payloadFingerprint,
                'payload_json' => json_encode($storedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'idempotency_key' => $idempotency
            ));
            $this->db->db_debug = $previousDebug;

            if (!$inserted) {
                $this->db->trans_rollback();
                // Dua worker dapat melewati pemeriksaan awal secara bersamaan.
                // Setelah rollback, payload identik diperlakukan sebagai retry.
                $raced = $this->db->select('id, village_id, installation_id, direction, aggregate_type, aggregate_id, operation, event_version, payload_fingerprint, payload_json')
                    ->where('idempotency_key', $idempotency)->get('sync_messages')->row_array();
                if ($raced && $this->sync_message_identity_matches(
                    $raced,
                    $installation,
                    $aggregateType,
                    $aggregateId,
                    $operation,
                    $payload,
                    $eventVersion,
                    $payloadFingerprint
                )) {
                    $accepted++;
                    $results[] = array('idempotency_key' => $idempotency, 'status' => 'duplicate');
                } else {
                    $rejected++;
                    $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Pesan duplikat atau belum dapat disimpan.');
                }
                continue;
            }

            $apply = array('success' => FALSE, 'message' => 'Jenis perubahan sinkronisasi tidak didukung.');
            if ($aggregateType === 'service_request' && $operation === 'status_update') {
                $apply = $this->apply_local_status_update($installation, $aggregateId, $payload);
            } elseif ($aggregateType === 'service_catalog' && $operation === 'upsert') {
                $apply = $this->apply_service_catalog($installation, $payload);
            } elseif ($aggregateType === 'resident_directory' && $operation === 'snapshot') {
                $apply = $this->apply_resident_directory_snapshot($installation, $payload);
            } elseif ($aggregateType === 'staff_accounts' && $operation === 'snapshot') {
                $apply = $this->apply_staff_accounts_snapshot($installation, $payload);
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

            if (!$this->db->trans_commit()) {
                $this->db->trans_rollback();
                $rejected++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Transaksi sinkronisasi belum dapat diselesaikan.');
                continue;
            }
            $accepted++;
            $results[] = array(
                'idempotency_key' => $idempotency,
                'status' => 'accepted',
                'message' => isset($apply['message']) ? $apply['message'] : 'Perubahan diterapkan.'
            );
        }

        return array('accepted' => $accepted, 'rejected' => $rejected, 'results' => $results);
    }

    private function apply_staff_accounts_snapshot(array $installation, array $payload)
    {
        $villageId=(string)$installation['village_id'];
        if (!$this->db->table_exists('warga_village_config_versions')
            || !$this->db->field_exists('credential_fingerprint', 'warga_staff_sources')) {
            return array('success'=>false,'message'=>'Jalankan migrasi database 013 dan 014 pada server.');
        }
        if (!isset($payload['staff'], $payload['verification'], $payload['contact'])
            || !is_array($payload['staff']) || !is_array($payload['verification']) || !is_array($payload['contact'])
            || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) ($payload['source_revision'] ?? ''))) {
            return array('success'=>false,'message'=>'Snapshot akun atau nomor revisi tidak lengkap.');
        }
        foreach (array('sekdes', 'kades') as $stage) {
            if (!array_key_exists($stage, $payload['verification']) || !is_bool($payload['verification'][$stage])) {
                return array('success'=>false,'message'=>'Pengaturan verifikasi harus berupa Ya/Tidak.');
            }
        }
        // The owning tenant row serializes snapshots, including the first one.
        $tenant=$this->db->query('SELECT settings_json FROM village_tenants WHERE id=? FOR UPDATE', array($villageId))->row_array();
        if (!$tenant) return array('success'=>false,'message'=>'Desa pemilik akun petugas tidak ditemukan.');
        $version=$this->db->where('village_id',$villageId)->get('warga_village_config_versions')->row_array();
        $revision=(int)$payload['source_revision'];
        if ($version && $revision <= (int)$version['source_revision']) {
            return array('success'=>true,'message'=>'Revisi akun lama diabaikan; pengaturan terbaru tetap dipakai.');
        }
        $staff=$payload['staff'];
        if (count($staff)>20) return array('success'=>false,'message'=>'Jumlah akun petugas melebihi batas.');
        $roles=$this->db->where_in('slug',array('sekdes','kepala-desa'))->get('roles')->result_array();
        $roleIds=array();
        foreach($roles as $role) $roleIds[$role['slug']]=(int)$role['id'];
        $kept=array();
        foreach($staff as $row) {
            if(!is_array($row) || !preg_match('/^[a-f0-9-]{32,36}$/i',(string)($row['id']??''))) return array('success'=>false,'message'=>'Identitas akun petugas tidak valid.');
            $id=(string)$row['id']; $role=str_replace('_','-',strtolower(trim((string)($row['role']??''))));
            $name=trim((string)($row['name']??'')); $email=strtolower(trim((string)($row['email']??'')));
            $hash=(string)($row['password_hash']??'');
            if(in_array($id,$kept,true) || !isset($roleIds[$role]) || $name==='' || mb_strlen($name)>160
                || strlen($email)>180 || !filter_var($email,FILTER_VALIDATE_EMAIL)
                || !preg_match('/^\$2y\$[0-9]{2}\$[A-Za-z0-9\.\/]{53}$/D',$hash)) return array('success'=>false,'message'=>'Data akun petugas tidak valid.');
            $existing=$this->db->where(array('village_id'=>$villageId,'local_id'=>$id))->get('warga_staff_sources')->row_array();
            $this->db->group_start()->where('email',$email)->or_where('username',$email)->group_end();
            if ($existing) $this->db->where('id !=',(int)$existing['user_id']);
            if ($this->db->count_all_results('users')) return array('success'=>false,'message'=>'Email petugas sudah digunakan akun lain.');
            $fingerprint=hash('sha256',$hash);
            $values=array('role_id'=>$roleIds[$role],'village_id'=>$villageId,'name'=>$name,'email'=>$email,
                'password_hash'=>$hash,'is_active'=>!empty($row['is_active'])?1:0,'updated_at'=>date('Y-m-d H:i:s'));
            if($existing) {
                // Do not undo a password changed in PWA when only contact or
                // workflow settings were edited locally.
                if (empty($existing['credential_fingerprint']) || hash_equals($existing['credential_fingerprint'],$fingerprint)) {
                    unset($values['password_hash']);
                }
                $this->db->where('id',(int)$existing['user_id'])->update('users',$values); $userId=(int)$existing['user_id'];
            } else {
                // The email remains the staff login. Local IDs are the stable
                // source key, while the generated username stays invisible.
                $username='warga-'.$villageId.'-'.substr(hash('sha256',$email),0,24);
                $values['username']=$username; $this->db->insert('users',$values); $userId=(int)$this->db->insert_id();
                if($userId<1) return array('success'=>false,'message'=>'Akun petugas belum dapat dibuat.');
            }
            $this->db->replace('warga_staff_sources',array('village_id'=>$villageId,'local_id'=>$id,'user_id'=>$userId,
                'source_revision'=>$revision,'credential_fingerprint'=>$fingerprint));
            $kept[]=$id;
        }
        // Accounts removed/disabled in Administrator > Akun are disabled
        // centrally; their local source record remains auditable.
        $this->db->where('village_id',$villageId);
        if($kept) $this->db->where_not_in('local_id',$kept);
        $removed=$this->db->get('warga_staff_sources')->result_array();
        foreach($removed as $row) $this->db->where('id',(int)$row['user_id'])->update('users',array('is_active'=>0));
        $settings=json_decode((string)($tenant['settings_json']??''),true); if(!is_array($settings))$settings=array();
        $settings['verification']=array('sekdes'=>!empty($payload['verification']['sekdes']),'kades'=>!empty($payload['verification']['kades']));
        $settings['contact']=array();
        foreach (array('institution'=>80,'address'=>1000,'phone'=>80,'email'=>180,'website'=>255,'office_hours'=>255) as $key=>$limit) {
            if (isset($payload['contact'][$key]) && !is_string($payload['contact'][$key])) return array('success'=>false,'message'=>'Format kontak desa tidak valid.');
            $settings['contact'][$key]=mb_substr(trim($payload['contact'][$key] ?? ''),0,$limit);
        }
        if ($settings['contact']['institution']==='') $settings['contact']['institution']='Desa';
        $this->db->where('id',$villageId)->update('village_tenants',array('settings_json'=>json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
        $this->db->replace('warga_village_config_versions',array('village_id'=>$villageId,'source_revision'=>$revision));
        return array('success'=>true,'message'=>'Akun petugas dan alur verifikasi diterapkan.');
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
                `source_revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `submission_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `availability_note` VARCHAR(500) DEFAULT NULL,
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
            $this->ensure_field('village_service_catalog', 'source_revision', "ALTER TABLE `village_service_catalog` ADD `source_revision` BIGINT UNSIGNED NOT NULL DEFAULT 0");
            $this->ensure_field('village_service_catalog', 'submission_enabled', "ALTER TABLE `village_service_catalog` ADD `submission_enabled` TINYINT(1) NOT NULL DEFAULT 1");
            $this->ensure_field('village_service_catalog', 'availability_note', "ALTER TABLE `village_service_catalog` ADD `availability_note` VARCHAR(500) DEFAULT NULL");
        }
        if (!$this->db->table_exists('village_service_catalog_state')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `village_service_catalog_state` (
                `village_id` CHAR(36) NOT NULL,
                `last_revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `last_hash` CHAR(64) DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`village_id`),
                CONSTRAINT `fk_catalog_state_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if ($this->db->table_exists('service_requests')) {
            $this->ensure_field('service_requests', 'catalog_service_id', "ALTER TABLE `service_requests` ADD `catalog_service_id` BIGINT UNSIGNED NULL");
            $this->ensure_field('service_requests', 'form_schema_version', "ALTER TABLE `service_requests` ADD `form_schema_version` INT UNSIGNED NULL");
            $this->ensure_field('service_requests', 'event_version', "ALTER TABLE `service_requests` ADD `event_version` BIGINT UNSIGNED NOT NULL DEFAULT 1");
            $this->ensure_index('service_requests', 'idx_requests_catalog', 'KEY `idx_requests_catalog` (`catalog_service_id`)');
        }
        if ($this->db->table_exists('sync_messages')) {
            $this->ensure_field('sync_messages', 'event_version', "ALTER TABLE `sync_messages` ADD `event_version` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `operation`");
            $this->ensure_field('sync_messages', 'payload_fingerprint', "ALTER TABLE `sync_messages` ADD `payload_fingerprint` CHAR(64) NULL AFTER `event_version`");
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
                `directory_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
                `directory_hash` CHAR(64) DEFAULT NULL,
                `snapshot_created_at` DATETIME NOT NULL,
                `batch_total` INT UNSIGNED NOT NULL DEFAULT 1,
                `finalized` TINYINT(1) NOT NULL DEFAULT 0,
                `finalized_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resident_snapshot` (`village_id`, `snapshot_id`),
                KEY `idx_resident_snapshot_latest` (`village_id`, `snapshot_created_at`),
                KEY `idx_resident_snapshot_version` (`village_id`, `directory_version`, `finalized`),
                CONSTRAINT `fk_resident_snapshot_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if ($this->db->table_exists('village_resident_snapshots')) {
            $this->ensure_field('village_resident_snapshots', 'directory_version', "ALTER TABLE `village_resident_snapshots` ADD `directory_version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `snapshot_id`");
            $this->ensure_field('village_resident_snapshots', 'directory_hash', "ALTER TABLE `village_resident_snapshots` ADD `directory_hash` CHAR(64) DEFAULT NULL AFTER `directory_version`");
            $this->ensure_index('village_resident_snapshots', 'idx_resident_snapshot_version', 'KEY `idx_resident_snapshot_version` (`village_id`, `directory_version`, `finalized`)');
        }
        if (!$this->db->table_exists('village_resident_snapshot_batches')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `village_resident_snapshot_batches` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `village_id` CHAR(36) NOT NULL,
                `snapshot_id` CHAR(64) NOT NULL,
                `batch_index` INT UNSIGNED NOT NULL,
                `batch_total` INT UNSIGNED NOT NULL,
                `batch_hash` CHAR(64) NOT NULL,
                `resident_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resident_snapshot_batch` (`village_id`, `snapshot_id`, `batch_index`),
                KEY `idx_resident_batch_snapshot` (`village_id`, `snapshot_id`),
                CONSTRAINT `fk_resident_batch_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if ($this->db->table_exists('village_resident_snapshot_batches')) {
            $this->ensure_field('village_resident_snapshot_batches', 'batch_hash', "ALTER TABLE `village_resident_snapshot_batches` ADD `batch_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `batch_total`");
        }
        if (!$this->db->table_exists('village_resident_directory_staging')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `village_resident_directory_staging` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `village_id` CHAR(36) NOT NULL,
                `snapshot_id` CHAR(64) NOT NULL,
                `local_citizen_key` VARCHAR(120) NOT NULL,
                `nik_hash` CHAR(64) NOT NULL,
                `kk_hash` CHAR(64) NOT NULL,
                `name_hash` CHAR(64) NOT NULL,
                `display_name` VARCHAR(160) NOT NULL,
                `birth_date` DATE DEFAULT NULL,
                `gender` VARCHAR(20) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resident_stage_source` (`village_id`, `snapshot_id`, `local_citizen_key`),
                UNIQUE KEY `uniq_resident_stage_nik` (`village_id`, `snapshot_id`, `nik_hash`),
                KEY `idx_resident_stage_snapshot` (`village_id`, `snapshot_id`),
                CONSTRAINT `fk_resident_stage_village` FOREIGN KEY (`village_id`) REFERENCES `village_tenants` (`id`) ON DELETE CASCADE
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
            && $this->db->field_exists('batch_hash', 'village_resident_snapshot_batches')
            && $this->db->table_exists('village_resident_directory_staging')
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
        $this->ensure_field('service_requests', 'document_format', "ALTER TABLE `service_requests` ADD `document_format` VARCHAR(10) NOT NULL DEFAULT 'pdf' AFTER `document_size`");
        $this->ensure_field('service_requests', 'event_version', "ALTER TABLE `service_requests` ADD `event_version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `status`");
        $this->official_document_schema_ready = $this->db->field_exists('document_sha256', 'service_requests')
            && $this->db->field_exists('document_size', 'service_requests')
            && $this->db->field_exists('document_format', 'service_requests')
            && $this->db->field_exists('event_version', 'service_requests');
        return $this->official_document_schema_ready;
    }

    public function publish_official_document(array $installation, $requestId, $reference, $path, $sha256, $size, $actorName, $format = 'pdf')
    {
        if (!in_array($format, array('pdf', 'html'), TRUE)) return array('success' => FALSE, 'message' => 'Format dokumen tidak valid.');
        if (!$this->ensure_official_document_schema()) {
            return array('success' => FALSE, 'message' => 'Struktur dokumen resmi belum siap.');
        }
        $request = $this->db
            ->select('id, citizen_user_id, status, event_version, local_reference, document_path, document_sha256, document_format')
            ->where(array('id' => (string) $requestId, 'village_id' => (string) $installation['village_id']))
            ->limit(1)
            ->get('service_requests')
            ->row_array();
        if (!$request) return array('success' => FALSE, 'message' => 'Permohonan tidak ditemukan pada desa ini.');

        $upgrade = (string) $request['status'] === 'issued' && $format === 'html'
            && (string) $request['document_format'] === 'pdf'
            && !empty($request['document_sha256'])
            && hash_equals((string) $request['local_reference'], trim((string) $reference));
        if ((string) $request['status'] === 'issued') {
            if ($request['document_format'] === $format && !empty($request['document_sha256']) && hash_equals((string) $request['document_sha256'], (string) $sha256)) {
                return array('success' => TRUE, 'duplicate' => TRUE, 'event_version' => max(1, (int) $request['event_version']), 'message' => 'Dokumen resmi ini sudah diterbitkan.');
            }
            if (!$upgrade) return array('success' => FALSE, 'message' => 'Permohonan sudah memiliki dokumen resmi yang berbeda.');
        }
        if ((string) $request['status'] !== 'approved' && !$upgrade) {
            return array('success' => FALSE, 'message' => 'Dokumen hanya dapat diterbitkan setelah permohonan disetujui.');
        }

        $now = date('Y-m-d H:i:s');
        $eventVersion = max(1, (int) $request['event_version']) + 1;
        if (!$this->db->trans_begin()) {
            return array('success' => FALSE, 'message' => 'Transaksi penerbitan belum dapat dimulai.');
        }
        $this->db->where('document_format', $request['document_format']);
        if ($upgrade) $this->db->where('document_sha256', $request['document_sha256']);
        $this->db
            ->where(array(
                'id' => (string) $requestId,
                'village_id' => (string) $installation['village_id'],
                'status' => $request['status'],
                'event_version' => (int) $request['event_version']
            ))
            ->update('service_requests', array(
                'status' => 'issued',
                'event_version' => $eventVersion,
                'local_reference' => substr(trim((string) $reference), 0, 160),
                'document_path' => (string) $path,
                'document_sha256' => (string) $sha256,
                'document_size' => (int) $size,
                'document_format' => $format,
                'local_sync_status' => 'synced',
                'local_synced_at' => $now
            ));
        if ($this->db->affected_rows() !== 1) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Status permohonan berubah karena diproses pengguna lain.');
        }
        $note = 'Dokumen resmi telah diterbitkan oleh ' . ($actorName !== '' ? $actorName : 'petugas desa') . '.';
        if (!$upgrade) $this->db->insert('request_status_history', array(
            'request_id' => (string) $requestId,
            'from_status' => $request['status'],
            'to_status' => 'issued',
            'note' => $note,
            'actor_id' => NULL,
            'occurred_at' => $now
        ));
        if (!$upgrade) {
            $notificationId = api_uuid();
            $this->db->insert('notifications', array(
            'id' => $notificationId,
            'user_id' => (int) $request['citizen_user_id'],
            'request_id' => (string) $requestId,
            'title' => 'Surat telah diterbitkan',
            'message' => 'Dokumen resmi sudah tersedia dan dapat diunduh dari aplikasi warga.'
            ));
            $this->db->insert('warga_notification_targets', array(
                'notification_id' => $notificationId,
                'target_path' => 'permohonan/' . (string) $requestId
            ));
        }
        if (!$this->db->trans_status()) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Penerbitan dokumen belum dapat disimpan.');
        }
        if (!$this->db->trans_commit()) {
            return array('success' => FALSE, 'message' => 'Transaksi penerbitan dokumen belum selesai.');
        }
        return array('success' => TRUE, 'duplicate' => FALSE,
            'event_version' => $eventVersion,
            'replaced_path' => $upgrade ? (string) $request['document_path'] : '',
            'message' => $upgrade ? 'Tampilan surat berhasil diperbarui menjadi HTML.' : 'Dokumen resmi berhasil diterbitkan.');
    }

    private function apply_service_catalog(array $installation, array $payload)
    {
        $villageId = trim((string) (isset($installation['village_id']) ? $installation['village_id'] : ''));
        $services = isset($payload['services']) && is_array($payload['services']) ? $payload['services'] : NULL;
        $catalogVersion = filter_var($payload['catalog_version'] ?? NULL, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $catalogHash = strtolower(trim((string) ($payload['catalog_hash'] ?? '')));
        $catalogEmpty = array_key_exists('catalog_empty', $payload) && $payload['catalog_empty'] === true;
        if ($villageId === '' || $services === NULL || count($services) > 200
            || (empty($services) && !$catalogEmpty) || (!empty($services) && $catalogEmpty)
            || $catalogVersion === FALSE || !preg_match('/^[a-f0-9]{64}$/', $catalogHash)) {
            return array('success' => FALSE, 'message' => 'Katalog layanan tidak valid.');
        }

        $tenant = $this->db->query('SELECT id FROM village_tenants WHERE id=? FOR UPDATE', array($villageId))->row_array();
        if (!$tenant) return array('success' => FALSE, 'message' => 'Desa pemilik katalog tidak ditemukan.');
        $state = $this->db->where('village_id', $villageId)->limit(1)->get('village_service_catalog_state')->row_array();
        $lastVersion = $state ? (int) ($state['last_revision'] ?? 0) : 0;
        $lastHash = $state ? strtolower((string) ($state['last_hash'] ?? '')) : '';
        if ((int) $catalogVersion < $lastVersion) {
            return array('success' => TRUE, 'message' => 'Katalog lama diabaikan.', 'stale' => TRUE, 'service_count' => 0);
        }
        if ((int) $catalogVersion === $lastVersion && $lastHash !== '' && !hash_equals($lastHash, $catalogHash)) {
            return array('success' => FALSE, 'message' => 'Versi katalog sudah digunakan oleh isi yang berbeda.');
        }
        if ((int) $catalogVersion === $lastVersion && $lastHash !== '' && hash_equals($lastHash, $catalogHash)) {
            return array('success' => TRUE, 'message' => 'Katalog sudah diterapkan.', 'stale' => TRUE, 'service_count' => count($services));
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
            if (isset($service['requirements'])) {
                if (!is_array($service['requirements']) || count($service['requirements']) > 50) {
                    return array('success' => FALSE, 'message' => 'Persyaratan layanan tidak valid atau melebihi batas.');
                }
                $seenRequirements = array();
                foreach ($service['requirements'] as $requirement) {
                    if (!is_scalar($requirement)) {
                        return array('success' => FALSE, 'message' => 'Persyaratan layanan tidak valid.');
                    }
                    $requirement = $this->catalog_text($requirement, 180);
                    if ($requirement === '') {
                        return array('success' => FALSE, 'message' => 'Persyaratan layanan tidak boleh kosong.');
                    }
                    $requirementKey = function_exists('mb_strtolower') ? mb_strtolower($requirement, 'UTF-8') : strtolower($requirement);
                    if (isset($seenRequirements[$requirementKey])) {
                        return array('success' => FALSE, 'message' => 'Persyaratan layanan tidak boleh duplikat.');
                    }
                    $seenRequirements[$requirementKey] = TRUE;
                    $requirements[] = $requirement;
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
            $availabilityNote = $this->catalog_text(isset($service['availability_note']) ? $service['availability_note'] : '', 500);
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
                'submission_enabled' => isset($service['submission_enabled']) ? (!empty($service['submission_enabled']) ? 1 : 0) : 1,
                'availability_note' => $availabilityNote !== '' ? $availabilityNote : NULL,
                'source_updated_at' => $sourceUpdated,
                'source_hash' => $sourceHash,
                'source_revision' => max(0, (int) ($service['source_revision'] ?? $catalogVersion))
            );
            $keys[$key] = TRUE;
        }
        $computedHash = hash('sha256', json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!hash_equals($catalogHash, $computedHash)) {
            return array('success' => FALSE, 'message' => 'Hash katalog layanan tidak sesuai dengan isinya.');
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
                'submission_enabled' => $service['submission_enabled'],
                'availability_note' => $service['availability_note'],
                'source_updated_at' => $service['source_updated_at'],
                'published_at' => date('Y-m-d H:i:s'),
                'source_hash' => $service['source_hash'],
                'source_revision' => $service['source_revision']
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
        $stateOk = $this->db->replace('village_service_catalog_state', array(
            'village_id' => $villageId,
            'last_revision' => (int) $catalogVersion,
            'last_hash' => $catalogHash,
            'updated_at' => date('Y-m-d H:i:s')
        ));
        if (!$stateOk || !$this->db->trans_status()) return array('success' => FALSE, 'message' => 'Versi katalog layanan belum dapat dicatat.');
        return array('success' => TRUE, 'message' => 'Katalog Layanan Warga tersimpan.', 'service_count' => count($normalised), 'catalog_version' => (int) $catalogVersion);
    }

    private function redact_resident_payload(array $payload)
    {
        $residents = isset($payload['residents']) && is_array($payload['residents']) ? $payload['residents'] : array();
        return array(
            'directory_version' => max(1, (int) (isset($payload['directory_version']) ? $payload['directory_version'] : 0)),
            'directory_hash' => strtolower(trim((string) (isset($payload['directory_hash']) ? $payload['directory_hash'] : ''))),
            'snapshot_id' => strtolower(trim((string) (isset($payload['snapshot_id']) ? $payload['snapshot_id'] : ''))),
            'snapshot_created_at' => $this->catalog_datetime(isset($payload['snapshot_created_at']) ? $payload['snapshot_created_at'] : '') ?: date('Y-m-d H:i:s'),
            'batch_index' => (int) (isset($payload['batch_index']) ? $payload['batch_index'] : 0),
            'batch_total' => (int) (isset($payload['batch_total']) ? $payload['batch_total'] : 0),
            'batch_hash' => strtolower(trim((string) (isset($payload['batch_hash']) ? $payload['batch_hash'] : ''))),
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
        $directoryVersion = (int) (isset($payload['directory_version']) ? $payload['directory_version'] : 0);
        $directoryHash = strtolower(trim((string) (isset($payload['directory_hash']) ? $payload['directory_hash'] : '')));
        $batchHash = strtolower(trim((string) (isset($payload['batch_hash']) ? $payload['batch_hash'] : '')));
        $snapshotCreated = $this->catalog_datetime(isset($payload['snapshot_created_at']) ? $payload['snapshot_created_at'] : '');
        $batchIndex = (int) (isset($payload['batch_index']) ? $payload['batch_index'] : 0);
        $batchTotal = (int) (isset($payload['batch_total']) ? $payload['batch_total'] : 0);
        $residents = isset($payload['residents']) && is_array($payload['residents']) ? $payload['residents'] : NULL;

        if ($villageId === '' || $directoryVersion < 1
            || !preg_match('/^[a-f0-9]{64}$/', $directoryHash)
            || !preg_match('/^[a-f0-9]{64}$/', $batchHash)
            || !preg_match('/^[a-f0-9]{64}$/', $snapshotId)
            || $snapshotCreated === NULL || $batchTotal < 1 || $batchTotal > 500
            || $batchIndex < 1 || $batchIndex > $batchTotal || $residents === NULL
            || count($residents) > 250) {
            return array('success' => FALSE, 'message' => 'Batch direktori penduduk tidak valid.');
        }

        $tenant = $this->db->query('SELECT id FROM village_tenants WHERE id=? FOR UPDATE', array($villageId))->row_array();
        if (!$tenant) return array('success' => FALSE, 'message' => 'Desa pemilik direktori tidak ditemukan.');

        // A retry from an older finalized snapshot must never reactivate
        // residents that a newer snapshot has already removed. Incomplete
        // snapshots do not become the active baseline.
        $latest = $this->db->where(array('village_id' => $villageId, 'finalized' => 1))
            ->order_by('directory_version', 'DESC')->order_by('id', 'DESC')
            ->limit(1)->get('village_resident_snapshots')->row_array();
        if ($latest && $directoryVersion < (int) $latest['directory_version']) {
            return array('success' => TRUE, 'message' => 'Batch direktori lama diabaikan.', 'stale' => TRUE);
        }
        if ($latest && $directoryVersion === (int) $latest['directory_version']
            && ((string) $latest['snapshot_id'] !== $snapshotId
                || (!empty($latest['directory_hash']) && !hash_equals((string) $latest['directory_hash'], $directoryHash)))) {
            return array('success' => FALSE, 'message' => 'Versi direktori sudah digunakan oleh snapshot yang berbeda.');
        }

        $normalised = array();
        $canonicalResidents = array();
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
            $canonicalResidents[] = array(
                'source_key' => $sourceKey,
                'nik' => $nik,
                'kk' => $kk,
                'name' => $name,
                'birth_date' => $birthDate
            );
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

        $canonicalJson = json_encode($canonicalResidents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $computedBatchHash = is_string($canonicalJson) ? hash('sha256', $canonicalJson) : '';
        if ($computedBatchHash === '' || !hash_equals($computedBatchHash, $batchHash)) {
            return array('success' => FALSE, 'message' => 'Hash batch direktori tidak cocok dengan isi data penduduk.');
        }

        $now = date('Y-m-d H:i:s');
        $versionOwner = $this->db->where(array(
            'village_id' => $villageId,
            'directory_version' => $directoryVersion
        ))->where('snapshot_id !=', $snapshotId)->limit(1)->get('village_resident_snapshots')->row_array();
        if ($versionOwner) {
            return array('success' => FALSE, 'message' => 'Nomor versi direktori sudah dipakai snapshot lain.');
        }
        $snapshot = $this->db->where(array('village_id' => $villageId, 'snapshot_id' => $snapshotId))
            ->limit(1)->get('village_resident_snapshots')->row_array();
        if (!$snapshot) {
            $ok = $this->db->insert('village_resident_snapshots', array(
                'village_id' => $villageId,
                'snapshot_id' => $snapshotId,
                'directory_version' => $directoryVersion,
                'directory_hash' => $directoryHash,
                'snapshot_created_at' => $snapshotCreated,
                'batch_total' => $batchTotal,
                'finalized' => 0,
                'created_at' => $now,
                'updated_at' => $now
            ));
            if (!$ok) return array('success' => FALSE, 'message' => 'Riwayat direktori penduduk belum dapat dibuat.');
        } elseif ((int) $snapshot['batch_total'] !== $batchTotal
            || (int) $snapshot['directory_version'] !== $directoryVersion
            || (string) $snapshot['snapshot_created_at'] !== $snapshotCreated
            || (!empty($snapshot['directory_hash']) && !hash_equals((string) $snapshot['directory_hash'], $directoryHash))) {
            return array('success' => FALSE, 'message' => 'Metadata snapshot direktori penduduk tidak konsisten.');
        } elseif (empty($snapshot['directory_hash'])) {
            $this->db->where('id', (int) $snapshot['id'])->update('village_resident_snapshots', array(
                'directory_hash' => $directoryHash,
                'updated_at' => $now
            ));
        }
        if ($snapshot && (int) $snapshot['finalized'] === 1) {
            return array('success' => TRUE, 'message' => 'Snapshot direktori sudah diaktifkan.', 'stale' => TRUE, 'snapshot_complete' => TRUE);
        }

        // Stage the batch only. The active directory is not touched until the
        // complete snapshot has every batch and passes final validation.
        foreach ($normalised as $resident) {
            $existingStage = $this->db->where(array(
                'village_id' => $villageId,
                'snapshot_id' => $snapshotId,
                'local_citizen_key' => $resident['source_key']
            ))->limit(1)->get('village_resident_directory_staging')->row_array();
            $stageData = array(
                'nik_hash' => $resident['nik_hash'],
                'kk_hash' => $resident['kk_hash'],
                'name_hash' => $resident['name_hash'],
                'display_name' => $resident['display_name'],
                'birth_date' => $resident['birth_date'],
                'gender' => $resident['gender'] !== '' ? $resident['gender'] : NULL,
                'updated_at' => $now
            );
            if ($existingStage) {
                $stageChanged = (string) $existingStage['nik_hash'] !== (string) $stageData['nik_hash']
                    || (string) $existingStage['kk_hash'] !== (string) $stageData['kk_hash']
                    || (string) $existingStage['name_hash'] !== (string) $stageData['name_hash']
                    || (string) $existingStage['display_name'] !== (string) $stageData['display_name']
                    || (string) $existingStage['birth_date'] !== (string) $stageData['birth_date']
                    || (string) $existingStage['gender'] !== (string) $stageData['gender'];
                if ($stageChanged) {
                    return array('success' => FALSE, 'message' => 'Data penduduk pada batch yang sama berubah. Kirim ulang snapshot secara utuh.');
                }
                $ok = $this->db->where('id', (int) $existingStage['id'])->update('village_resident_directory_staging', $stageData);
            } else {
                $stageData['village_id'] = $villageId;
                $stageData['snapshot_id'] = $snapshotId;
                $stageData['local_citizen_key'] = $resident['source_key'];
                $ok = $this->db->insert('village_resident_directory_staging', $stageData);
            }
            if (!$ok) return array('success' => FALSE, 'message' => 'Batch direktori penduduk belum dapat ditempatkan di staging.');
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
                'batch_hash' => $batchHash,
                'resident_count' => count($normalised),
                'created_at' => $now
            ));
            if (!$ok) return array('success' => FALSE, 'message' => 'Batch direktori penduduk belum dapat dicatat.');
        } elseif ((int) $batch['batch_total'] !== $batchTotal
            || (int) $batch['resident_count'] !== count($normalised)
            || empty($batch['batch_hash'])
            || !hash_equals((string) $batch['batch_hash'], $batchHash)) {
            return array('success' => FALSE, 'message' => 'Isi pengiriman ulang batch direktori tidak konsisten.');
        }

        $batchRows = $this->db->select('batch_index, batch_total, resident_count, batch_hash')->where(array('village_id' => $villageId, 'snapshot_id' => $snapshotId))
            ->get('village_resident_snapshot_batches')->result_array();
        $receivedIndexes = array();
        $receivedHashes = array();
        $expectedResidentCount = 0;
        foreach ($batchRows as $batchRow) {
            $storedBatchIndex = (int) $batchRow['batch_index'];
            $storedBatchTotal = (int) $batchRow['batch_total'];
            $storedResidentCount = (int) $batchRow['resident_count'];
            if ($storedBatchIndex < 1 || $storedBatchIndex > $batchTotal
                || $storedBatchTotal !== $batchTotal
                || $storedResidentCount < 0 || $storedResidentCount > 250
                || isset($receivedIndexes[$storedBatchIndex])) {
                return array('success' => FALSE, 'message' => 'Jumlah batch snapshot tidak konsisten.');
            }
            $receivedIndexes[$storedBatchIndex] = TRUE;
            if (!preg_match('/^[a-f0-9]{64}$/', (string) $batchRow['batch_hash'])) {
                return array('success' => FALSE, 'message' => 'Hash batch snapshot tidak valid.');
            }
            $receivedHashes[$storedBatchIndex] = (string) $batchRow['batch_hash'];
            $expectedResidentCount += $storedResidentCount;
        }
        $complete = count($receivedIndexes) === $batchTotal;
        for ($expected = 1; $complete && $expected <= $batchTotal; $expected++) {
            if (!isset($receivedIndexes[$expected])) $complete = FALSE;
        }
        if ($complete) {
            $orderedHashes = array();
            for ($expected = 1; $expected <= $batchTotal; $expected++) $orderedHashes[] = $receivedHashes[$expected];
            $computedDirectoryHash = hash('sha256', 'v1|' . implode('|', $orderedHashes));
            if (!hash_equals($computedDirectoryHash, $directoryHash)) {
                return array('success' => FALSE, 'message' => 'Hash direktori tidak cocok dengan seluruh batch penduduk.');
            }
            $stagedRows = $this->db->where(array('village_id' => $villageId, 'snapshot_id' => $snapshotId))
                ->order_by('id', 'ASC')->get('village_resident_directory_staging')->result_array();
            if (count($stagedRows) !== $expectedResidentCount) {
                return array('success' => FALSE, 'message' => 'Snapshot belum utuh; jumlah penduduk tidak cocok dengan seluruh batch.');
            }
            $stagedKeys = array();
            $stagedNiks = array();
            foreach ($stagedRows as $stagedRow) {
                $stagedKeys[(string) $stagedRow['local_citizen_key']] = TRUE;
                if (isset($stagedNiks[(string) $stagedRow['nik_hash']])) {
                    return array('success' => FALSE, 'message' => 'NIK ganda ditemukan dalam snapshot penduduk.');
                }
                $stagedNiks[(string) $stagedRow['nik_hash']] = TRUE;
            }

            $latest = $this->db->where(array('village_id' => $villageId, 'finalized' => 1))
                ->order_by('directory_version', 'DESC')->order_by('id', 'DESC')
                ->limit(1)->get('village_resident_snapshots')->row_array();
            if ($latest && (string) $latest['snapshot_id'] === $snapshotId) {
                return array('success' => TRUE, 'message' => 'Snapshot direktori sudah diaktifkan.', 'stale' => TRUE, 'snapshot_complete' => TRUE);
            }
            if ($latest && (int) $latest['directory_version'] >= $directoryVersion) {
                return array('success' => TRUE, 'message' => 'Snapshot direktori lama tidak diaktifkan.', 'stale' => TRUE, 'snapshot_complete' => FALSE);
            }

            // Keep the old rows in memory for account revalidation, then swap
            // the active directory in one enclosing database transaction.
            $activeRows = $this->db->where('village_id', $villageId)->get('village_resident_directory')->result_array();
            $activeByKey = array();
            foreach ($activeRows as $activeRow) $activeByKey[(string) $activeRow['local_citizen_key']] = $activeRow;
            if ($this->db->table_exists('citizen_profiles')) {
                foreach ($activeRows as $activeRow) {
                    if (!isset($stagedKeys[(string) $activeRow['local_citizen_key']])) {
                        $this->db->where(array('village_id' => $villageId, 'local_citizen_key' => $activeRow['local_citizen_key']))
                            ->where_in('verification_status', array('verified', 'revalidation_required'))
                            ->update('citizen_profiles', array('verification_status' => 'inactive', 'updated_at' => $now));
                    }
                }
            }
            $this->db->where('village_id', $villageId)->delete('village_resident_directory');
            if ($this->db->trans_status() === FALSE) return array('success' => FALSE, 'message' => 'Direktori penduduk lama belum dapat diganti.');

            foreach ($stagedRows as $stagedRow) {
                $this->db->insert('village_resident_directory', array(
                    'village_id' => $villageId,
                    'local_citizen_key' => $stagedRow['local_citizen_key'],
                    'nik_hash' => $stagedRow['nik_hash'],
                    'kk_hash' => $stagedRow['kk_hash'],
                    'name_hash' => $stagedRow['name_hash'],
                    'display_name' => $stagedRow['display_name'],
                    'birth_date' => $stagedRow['birth_date'],
                    'gender' => $stagedRow['gender'],
                    'snapshot_id' => $snapshotId,
                    'status' => 'active',
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now
                ));
                if ($this->db->trans_status() === FALSE) return array('success' => FALSE, 'message' => 'Direktori penduduk baru belum dapat diaktifkan.');
                $old = isset($activeByKey[(string) $stagedRow['local_citizen_key']]) ? $activeByKey[(string) $stagedRow['local_citizen_key']] : NULL;
                $identityChanged = $old && ((string) $old['nik_hash'] !== (string) $stagedRow['nik_hash']
                    || (string) $old['kk_hash'] !== (string) $stagedRow['kk_hash']
                    || (string) $old['name_hash'] !== (string) $stagedRow['name_hash']);
                if ($identityChanged && $this->db->table_exists('citizen_profiles')) {
                    $this->db->where(array('village_id' => $villageId, 'local_citizen_key' => $stagedRow['local_citizen_key']))
                        ->where('verification_status', 'verified')
                        ->update('citizen_profiles', array('verification_status' => 'revalidation_required', 'updated_at' => $now));
                }
            }
            $finalized = $this->db->where(array(
                'village_id' => $villageId,
                'snapshot_id' => $snapshotId,
                'finalized' => 0
            ))->update('village_resident_snapshots', array(
                'finalized' => 1,
                'finalized_at' => $now,
                'updated_at' => $now
            ));
            if (!$finalized || $this->db->affected_rows() !== 1 || !$this->db->trans_status()) {
                return array('success' => FALSE, 'message' => 'Snapshot penduduk belum dapat ditandai selesai.');
            }
            $stagingDeleted = $this->db->where(array(
                'village_id' => $villageId,
                'snapshot_id' => $snapshotId
            ))->delete('village_resident_directory_staging');
            if (!$stagingDeleted || !$this->db->trans_status()) {
                return array('success' => FALSE, 'message' => 'Staging snapshot penduduk belum dapat dibersihkan.');
            }
        }

        return array(
            'success' => TRUE,
            'message' => $complete ? 'Direktori penduduk tersimpan dan diaktifkan.' : 'Batch direktori penduduk tersimpan.',
            'snapshot_complete' => $complete,
            'directory_version' => $directoryVersion,
            'resident_count' => count($normalised)
        );
    }

    private function apply_local_status_update(array $installation, $aggregateId, array $payload)
    {
        // Lock the request before evaluating the workflow. Two deliveries for
        // the same request must observe one status/version pair, even when
        // they arrive in the same HTTP batch.
        $request = $this->db->query(
            'SELECT * FROM service_requests WHERE id=? AND village_id=? FOR UPDATE',
            array((string) $aggregateId, (string) $installation['village_id'])
        )->row_array();
        if (!$request) {
            return array('success' => FALSE, 'message' => 'Permohonan tidak ditemukan pada desa ini.');
        }

        $incomingRaw = $payload['event_version'] ?? NULL;
        if ((!is_int($incomingRaw) && !is_string($incomingRaw))
            || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $incomingRaw)) {
            return array('success' => FALSE, 'message' => 'Versi perubahan status tidak valid.');
        }
        $incomingVersion = (int) $incomingRaw;
        $currentVersion = max(0, (int) ($request['event_version'] ?? 0));
        if ($incomingVersion <= $currentVersion) {
            return array('success' => TRUE, 'message' => 'Perubahan status lama diabaikan.', 'stale' => TRUE);
        }

        $fromStatus = trim((string) $request['status']);
        $toStatus = trim((string) (isset($payload['status']) ? $payload['status'] : (isset($payload['to_status']) ? $payload['to_status'] : '')));
        $actorRole = strtolower(trim((string) (isset($payload['actor_role']) ? $payload['actor_role'] : '')));
        $actorRole = str_replace('_', '-', $actorRole);
        $note = trim((string) (isset($payload['note']) ? $payload['note'] : ''));
        $actorName = $this->catalog_text(isset($payload['actor_name']) ? $payload['actor_name'] : 'Petugas Desa', 160);
        if (array_key_exists('from_status', $payload)
            && trim((string) $payload['from_status']) !== $fromStatus) {
            return array('success' => FALSE, 'message' => 'Status awal perubahan tidak sesuai dengan status permohonan.');
        }
        if (!in_array($toStatus, array('verified', 'approved', 'revision', 'rejected'), TRUE)) {
            return array('success' => FALSE, 'message' => 'Status tujuan perubahan tidak valid.');
        }
        if (strlen($note) > 1000) {
            return array('success' => FALSE, 'message' => 'Catatan perubahan maksimal 1.000 karakter.');
        }

        $tenant = $this->db->query(
            'SELECT settings_json FROM village_tenants WHERE id=? FOR UPDATE',
            array((string) $installation['village_id'])
        )->row_array();
        if (!$tenant) return array('success' => FALSE, 'message' => 'Desa pemilik permohonan tidak ditemukan.');
        $settingsRaw = trim((string) ($tenant['settings_json'] ?? ''));
        $settings = $settingsRaw !== '' ? json_decode($settingsRaw, TRUE) : array();
        if (!is_array($settings)) return array('success' => FALSE, 'message' => 'Pengaturan alur verifikasi desa tidak valid.');
        $verification = array('sekdes' => TRUE, 'kades' => TRUE);
        if (isset($settings['verification'])) {
            if (!is_array($settings['verification'])) return array('success' => FALSE, 'message' => 'Pengaturan alur verifikasi desa tidak valid.');
            foreach (array('sekdes', 'kades') as $stage) {
                if (array_key_exists($stage, $settings['verification'])) {
                    $value = $settings['verification'][$stage];
                    if (is_bool($value)) $verification[$stage] = $value;
                    elseif ($value === 0 || $value === 1 || $value === '0' || $value === '1') $verification[$stage] = (bool) $value;
                    else return array('success' => FALSE, 'message' => 'Pengaturan alur verifikasi desa tidak valid.');
                }
            }
        }

        $allowed = FALSE;
        if ($actorRole === 'sekdes') {
            $allowed = $verification['sekdes'] && $fromStatus === 'submitted'
                && ($toStatus === ($verification['kades'] ? 'verified' : 'approved') || in_array($toStatus, array('revision','rejected'), TRUE));
        } elseif (in_array($actorRole, array('kepala-desa', 'kades'), TRUE)) {
            $allowed = $verification['kades'] && ($fromStatus === 'verified' || (!$verification['sekdes'] && $fromStatus === 'submitted'))
                && in_array($toStatus,array('approved','revision','rejected'),TRUE);
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
            ->where(array('id' => (string) $aggregateId, 'village_id' => $installation['village_id'], 'status' => $fromStatus, 'event_version' => $currentVersion))
            ->update('service_requests', array(
                'status' => $toStatus,
                'event_version' => $incomingVersion,
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
        if (!$this->db->trans_status()) return array('success' => FALSE, 'message' => 'Riwayat status belum dapat disimpan.');
        $serviceName = !empty($request['service_type_id']) ? 'Permohonan layanan' : 'Permohonan warga';
        $this->db->insert('notifications', array(
            'id' => $notificationId = api_uuid(),
            'user_id' => (int) $request['citizen_user_id'],
            'request_id' => (string) $aggregateId,
            'title' => $serviceName,
            'message' => $toStatus . ' oleh ' . ($actorName !== '' ? $actorName : 'petugas desa') . '. ' . $note
        ));
        $this->db->insert('warga_notification_targets', array(
            'notification_id' => $notificationId,
            'target_path' => 'permohonan/' . (string) $aggregateId
        ));
        if (!$this->db->trans_status()) return array('success' => FALSE, 'message' => 'Notifikasi perubahan status belum dapat disimpan.');
        if ($toStatus === 'verified' && $verification['kades']) {
            $staff=$this->db->select('u.id')->from('users u')->join('roles r','r.id=u.role_id')
                ->where(array('u.village_id'=>$installation['village_id'],'u.is_active'=>1,'r.slug'=>'kepala-desa'))->get()->result_array();
            foreach ($staff as $user) {
                $staffNotification=api_uuid();
                $this->db->insert('notifications',array('id'=>$staffNotification,'user_id'=>$user['id'],'request_id'=>(string)$aggregateId,
                    'title'=>'Permohonan menunggu persetujuan','message'=>'Permohonan surat telah diverifikasi oleh petugas desa.'));
                $this->db->insert('warga_notification_targets',array('notification_id'=>$staffNotification,'target_path'=>'petugas/permohonan/'.$aggregateId));
                if (!$this->db->trans_status()) return array('success' => FALSE, 'message' => 'Notifikasi petugas belum dapat disimpan.');
            }
        }

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
        if (isset($raw['fields']) && !is_array($raw['fields'])) {
            $error = 'Daftar isian formulir tidak valid.';
            return FALSE;
        }
        $fields = isset($raw['fields']) ? $raw['fields'] : array();
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
                if (count($field['options']) > 50) {
                    $error = 'Pilihan formulir maksimal 50 item.';
                    return FALSE;
                }
                $seenOptions = array();
                foreach ($field['options'] as $option) {
                    if (!is_array($option)) {
                        $error = 'Pilihan formulir tidak valid.';
                        return FALSE;
                    }
                    $value = $this->catalog_text(isset($option['value']) ? $option['value'] : '', 100);
                    $optionLabel = $this->catalog_text(isset($option['label']) ? $option['label'] : $value, 180);
                    $optionKey = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
                    if ($value === '' || $optionLabel === '' || isset($seenOptions[$optionKey])) {
                        $error = 'Pilihan formulir tidak boleh kosong.';
                        return FALSE;
                    }
                    $seenOptions[$optionKey] = TRUE;
                    $options[] = array('value' => $value, 'label' => $optionLabel);
                }
                if (empty($options)) {
                    $error = 'Pilihan formulir belum valid.';
                    return FALSE;
                }
            }
            $rawAccept = isset($field['accept']) ? $field['accept'] : '';
            if (!is_scalar($rawAccept)) {
                $error = 'Tipe berkas formulir tidak valid.';
                return FALSE;
            }
            $accept = strtolower(trim((string) $rawAccept));
            if ($type !== 'file' && $accept !== '') {
                $error = 'Batas tipe berkas hanya boleh digunakan pada isian berkas.';
                return FALSE;
            }
            $acceptParts = $accept === '' ? array() : preg_split('/\s*,\s*/', $accept);
            $acceptedTypes = array('image/jpeg', 'image/png', 'image/*', 'application/pdf', '.jpg', '.jpeg', '.png', '.pdf');
            $cleanAccept = array();
            foreach ($acceptParts as $part) {
                if ($part === '' || !in_array($part, $acceptedTypes, TRUE) || in_array($part, $cleanAccept, TRUE)) {
                    $error = 'Batas tipe berkas formulir tidak valid.';
                    return FALSE;
                }
                $cleanAccept[] = $part;
            }
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

    private function sync_event_version($aggregateType, $operation, array $message, array $payload, &$error)
    {
        $error = '';
        $keys = array(
            'service_request:status_update' => 'event_version',
            'service_catalog:upsert' => 'catalog_version',
            'resident_directory:snapshot' => 'directory_version',
            'staff_accounts:snapshot' => 'source_revision'
        );
        $identity = (string) $aggregateType . ':' . (string) $operation;
        if (!isset($keys[$identity])) {
            $error = 'Jenis perubahan sinkronisasi tidak didukung.';
            return 0;
        }
        $key = $keys[$identity];
        $raw = isset($payload[$key]) ? $payload[$key] : NULL;
        if ((!is_int($raw) && !is_string($raw))
            || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $raw)) {
            $error = 'Nomor versi pesan sinkronisasi tidak valid.';
            return 0;
        }
        $version = (int) $raw;
        if (array_key_exists('event_version', $message)) {
            $envelope = $message['event_version'];
            if ((!is_int($envelope) && !is_string($envelope))
                || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $envelope)
                || (int) $envelope !== $version) {
                $error = 'Versi amplop pesan tidak cocok dengan versi payload.';
                return 0;
            }
        }
        return $version;
    }

    private function sync_payload_fingerprint(array $payload)
    {
        $canonical = json_encode(
            $this->canonical_sync_value($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($canonical)) $canonical = '{}';
        $key = (string) getenv('APP_KEY');
        return $key !== '' ? hash_hmac('sha256', $canonical, $key) : hash('sha256', $canonical);
    }

    private function canonical_sync_value($value)
    {
        if (!is_array($value)) return $value;
        $isList = empty($value) || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonical_sync_value($item);
        return $value;
    }

    private function sync_message_payload_matches(array $existing, array $payload, $eventVersion, $fingerprint)
    {
        $stored = json_decode((string) (isset($existing['payload_json']) ? $existing['payload_json'] : ''), TRUE);
        if (!is_array($stored)) return FALSE;
        $storedVersion = (int) (isset($existing['event_version']) ? $existing['event_version'] : 0);
        if ($storedVersion < 1) {
            $versionError = '';
            $storedVersion = $this->sync_event_version(
                (string) $existing['aggregate_type'],
                (string) $existing['operation'],
                array(),
                $stored,
                $versionError
            );
        }
        if ($storedVersion !== (int) $eventVersion) return FALSE;

        $storedFingerprint = strtolower(trim((string) (isset($existing['payload_fingerprint']) ? $existing['payload_fingerprint'] : '')));
        if (preg_match('/^[a-f0-9]{64}$/', $storedFingerprint)) {
            return hash_equals($storedFingerprint, (string) $fingerprint);
        }

        $type = (string) $existing['aggregate_type'];
        if ($type === 'resident_directory') {
            $incomingCount = isset($payload['residents']) && is_array($payload['residents']) ? count($payload['residents']) : -1;
            foreach (array('directory_version', 'snapshot_id', 'batch_index', 'batch_total') as $key) {
                if ((string) (isset($stored[$key]) ? $stored[$key] : '') !== (string) (isset($payload[$key]) ? $payload[$key] : '')) return FALSE;
            }
            if ((int) (isset($stored['resident_count']) ? $stored['resident_count'] : -1) !== $incomingCount) return FALSE;
            if (!empty($stored['directory_hash'])
                && !hash_equals((string) $stored['directory_hash'], (string) (isset($payload['directory_hash']) ? $payload['directory_hash'] : ''))) return FALSE;
            if (!empty($stored['batch_hash'])
                && !hash_equals((string) $stored['batch_hash'], (string) (isset($payload['batch_hash']) ? $payload['batch_hash'] : ''))) return FALSE;
            return TRUE;
        }
        if ($type === 'staff_accounts') {
            return hash_equals((string) (isset($stored['snapshot_hash']) ? $stored['snapshot_hash'] : ''), (string) (isset($payload['snapshot_hash']) ? $payload['snapshot_hash'] : ''))
                && (int) (isset($stored['staff_count']) ? $stored['staff_count'] : -1) === count(isset($payload['staff']) && is_array($payload['staff']) ? $payload['staff'] : array());
        }
        return hash_equals($this->sync_payload_fingerprint($stored), (string) $fingerprint);
    }

    private function sync_message_identity_matches(
        array $existing,
        array $installation,
        $aggregateType,
        $aggregateId,
        $operation,
        array $payload,
        $eventVersion,
        $fingerprint
    ) {
        return (string) $existing['village_id'] === (string) $installation['village_id']
            && (string) ($existing['installation_id'] ?? '') === (string) ($installation['id'] ?? '')
            && (string) $existing['direction'] === 'local_to_cloud'
            && (string) $existing['aggregate_type'] === (string) $aggregateType
            && (string) $existing['aggregate_id'] === (string) $aggregateId
            && (string) $existing['operation'] === (string) $operation
            && $this->sync_message_payload_matches($existing, $payload, $eventVersion, $fingerprint);
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
