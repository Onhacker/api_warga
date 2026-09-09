<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Installation_rebind_model extends CI_Model
{
    public function move(array $input)
    {
        if (!$this->schema_ready()) {
            return $this->failure('migration_required', 'Skema audit pindah kampung belum dipasang.');
        }

        $appKey = trim((string) getenv('APP_KEY'));
        if (strlen($appKey) < 32 || preg_match('/replace-with|ganti-dengan|change-before|example/i', $appKey)) {
            return $this->failure('credentials_unavailable', 'APP_KEY API belum aman untuk merotasi kredensial instalasi.');
        }

        $operationId = strtolower(trim((string) $input['operation_id']));
        $sourceCode = strtoupper(trim((string) $input['source_village_code']));
        $targetCode = strtoupper(trim((string) $input['target_village_code']));
        $reason = trim((string) $input['reason']);
        $actor = trim((string) $input['actor']);

        if (!$this->db->trans_begin()) {
            return $this->failure('service_unavailable', 'Transaksi pindah kampung belum dapat dimulai.');
        }

        $existingQuery = $this->db->query(
            'SELECT operation_id, source_village_code, target_village_code FROM installation_rebind_audit WHERE operation_id = ? LIMIT 1 FOR UPDATE',
            array($operationId)
        );
        if (!$existingQuery) return $this->rollback_failure('service_unavailable', 'Audit pindah kampung belum dapat diperiksa.');
        $existing = $existingQuery->row_array();
        if ($existing) {
            if (!hash_equals((string) $existing['source_village_code'], $sourceCode)
                || !hash_equals((string) $existing['target_village_code'], $targetCode)) {
                return $this->rollback_failure('binding_conflict', 'Identitas operasi rebind telah digunakan untuk pemindahan lain.');
            }
            if (!$this->db->trans_commit()) {
                return $this->failure('service_unavailable', 'Status operasi sebelumnya belum dapat dikonfirmasi.');
            }
            return array(
                'success' => TRUE,
                'operation_id' => $operationId,
                'already_applied' => TRUE,
                'message' => 'Penyiapan ulang kredensial sebelumnya sudah selesai.'
            );
        }

        $rowsQuery = $this->db->query(
            "SELECT i.*, v.village_code, v.name AS village_name, v.district_name, v.regency_name
             FROM village_installations i
             INNER JOIN village_tenants v ON v.id = i.village_id
             WHERE v.village_code IN (?, ?) AND v.status = 'active' AND i.status = 'active'
             ORDER BY v.village_code, i.updated_at DESC, i.id DESC
             FOR UPDATE",
            array($sourceCode, $targetCode)
        );
        if (!$rowsQuery) return $this->rollback_failure('service_unavailable', 'Instalasi kampung belum dapat diperiksa.');

        $grouped = array($sourceCode => array(), $targetCode => array());
        foreach ($rowsQuery->result_array() as $row) {
            $code = strtoupper(trim((string) $row['village_code']));
            if (isset($grouped[$code])) $grouped[$code][] = $row;
        }
        if (empty($grouped[$sourceCode])) return $this->rollback_failure('source_not_found', 'Instalasi aktif kampung sumber tidak ditemukan.');
        if (empty($grouped[$targetCode])) return $this->rollback_failure('target_not_found', 'Instalasi aktif kampung tujuan tidak ditemukan.');
        if (count($grouped[$sourceCode]) !== 1 || count($grouped[$targetCode]) !== 1) {
            return $this->rollback_failure('multiple_installations', 'Sumber atau tujuan memiliki lebih dari satu instalasi aktif. Rapikan data instalasi terlebih dahulu.');
        }

        $source = $grouped[$sourceCode][0];
        $target = $grouped[$targetCode][0];
        $sourceSecret = $this->new_encrypted_secret($appKey);
        $targetSecret = $this->new_encrypted_secret($appKey);
        if ($sourceSecret === FALSE || $targetSecret === FALSE) {
            return $this->rollback_failure('credentials_unavailable', 'Kredensial baru belum dapat dibuat dengan aman.');
        }

        $now = date('Y-m-d H:i:s');
        foreach (array($source['id'] => $sourceSecret, $target['id'] => $targetSecret) as $installationId => $credential) {
            $updated = $this->db->where('id', $installationId)->update('village_installations', array(
                'sync_secret_hash' => $credential['hash'],
                'sync_secret_encrypted' => $credential['encrypted'],
                'enrollment_code_hash' => NULL,
                'enrollment_expires_at' => NULL,
                'enrollment_used_at' => NULL,
                'enrollment_device_hash' => NULL,
                'enrollment_hardware_hash' => NULL,
                'app_version' => NULL,
                'last_seen_at' => NULL,
                'last_sync_at' => NULL,
                'updated_at' => $now
            ));
            if (!$updated) return $this->rollback_failure('service_unavailable', 'Kredensial instalasi belum dapat dirotasi.');
            if ($this->db->table_exists('api_request_nonces')) {
                $this->db->where('installation_id', $installationId)->delete('api_request_nonces');
            }
        }

        $details = array(
            'source' => $this->audit_snapshot($source),
            'target' => $this->audit_snapshot($target)
        );
        $inserted = $this->db->insert('installation_rebind_audit', array(
            'operation_id' => $operationId,
            'source_village_id' => $source['village_id'],
            'target_village_id' => $target['village_id'],
            'source_installation_id' => $source['id'],
            'target_installation_id' => $target['id'],
            'source_village_code' => $sourceCode,
            'target_village_code' => $targetCode,
            'source_device_hash_prefix' => $this->hash_prefix(isset($source['enrollment_device_hash']) ? $source['enrollment_device_hash'] : ''),
            'target_device_hash_prefix' => $this->hash_prefix(isset($target['enrollment_device_hash']) ? $target['enrollment_device_hash'] : ''),
            'source_hardware_hash_prefix' => $this->hash_prefix(isset($source['enrollment_hardware_hash']) ? $source['enrollment_hardware_hash'] : ''),
            'target_hardware_hash_prefix' => $this->hash_prefix(isset($target['enrollment_hardware_hash']) ? $target['enrollment_hardware_hash'] : ''),
            'reason' => $reason,
            'actor' => $actor,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now
        ));
        if (!$inserted || !$this->db->trans_status()) {
            return $this->rollback_failure('service_unavailable', 'Audit pindah kampung belum dapat disimpan.');
        }
        if (!$this->db->trans_commit()) {
            return $this->failure('service_unavailable', 'Pindah kampung belum dapat diselesaikan.');
        }

        return array(
            'success' => TRUE,
            'operation_id' => $operationId,
            'already_applied' => FALSE,
            'message' => 'Kredensial sumber dan tujuan telah dirotasi. Laptop dapat dihubungkan ke kampung tujuan.',
            'source' => $this->public_village($source),
            'target' => $this->public_village($target)
        );
    }

    private function schema_ready()
    {
        foreach (array('village_tenants', 'village_installations', 'installation_rebind_audit') as $table) {
            if (!$this->db->table_exists($table)) return FALSE;
        }
        foreach (array('sync_secret_encrypted', 'enrollment_used_at', 'enrollment_device_hash', 'enrollment_hardware_hash') as $field) {
            if (!$this->db->field_exists($field, 'village_installations')) return FALSE;
        }
        return TRUE;
    }

    private function new_encrypted_secret($appKey)
    {
        if (!function_exists('openssl_encrypt')) return FALSE;
        try {
            $secret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $iv = random_bytes(12);
        } catch (Exception $e) {
            return FALSE;
        }
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            hash('sha256', $appKey, TRUE),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'smartdesa-warga-api'
        );
        if ($ciphertext === FALSE || strlen($tag) !== 16) return FALSE;
        return array(
            'hash' => hash('sha256', $secret),
            'encrypted' => base64_encode($iv . $tag . $ciphertext)
        );
    }

    private function audit_snapshot(array $row)
    {
        return array(
            'village_code' => (string) $row['village_code'],
            'village_name' => (string) $row['village_name'],
            'installation_id' => (string) $row['id'],
            'installation_code_masked' => $this->mask_code(isset($row['installation_code']) ? $row['installation_code'] : ''),
            'enrollment_used_at' => isset($row['enrollment_used_at']) ? $row['enrollment_used_at'] : NULL,
            'last_seen_at' => isset($row['last_seen_at']) ? $row['last_seen_at'] : NULL,
            'last_sync_at' => isset($row['last_sync_at']) ? $row['last_sync_at'] : NULL
        );
    }

    private function public_village(array $row)
    {
        return array(
            'village_code' => (string) $row['village_code'],
            'village_name' => (string) $row['village_name'],
            'district_name' => (string) $row['district_name'],
            'regency_name' => (string) $row['regency_name']
        );
    }

    private function hash_prefix($value)
    {
        $value = strtolower(trim((string) $value));
        return preg_match('/^[a-f0-9]{64}$/', $value) ? substr($value, 0, 12) : NULL;
    }

    private function mask_code($value)
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        return strlen($value) <= 8 ? str_repeat('*', strlen($value)) : substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
    }

    private function rollback_failure($error, $message)
    {
        $dbError = $this->db->error();
        $this->db->trans_rollback();
        if (!empty($dbError['code'])) log_message('error', 'Rebind API gagal: ' . json_encode($dbError));
        return $this->failure($error, $message);
    }

    private function failure($error, $message)
    {
        return array('success' => FALSE, 'error' => $error, 'message' => $message);
    }
}
