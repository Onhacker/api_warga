<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Installations extends MY_Controller
{
    public function villages()
    {
        if (!$this->require_method('GET')) return;
        if (!isset($this->db)) return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');

        $query = $this->db
            ->select('province_code, province_name, regency_code, regency_name, district_code, district_name, village_code, name AS village_name')
            ->from('village_tenants')
            ->where('status', 'active')
            ->order_by('regency_name', 'ASC')
            ->order_by('district_name', 'ASC')
            ->order_by('name', 'ASC')
            ->get();
        if (!$query) {
            log_message('error', 'Gagal memuat daftar wilayah untuk enrollment: ' . json_encode($this->db->error()));
            return $this->fail('Daftar wilayah belum dapat dimuat.', 503, 'service_unavailable');
        }
        $rows = $query->result_array();

        return $this->respond(array(
            'success' => TRUE,
            'count' => count($rows),
            'villages' => $rows,
            'server_time' => date('c')
        ));
    }

    public function enroll()
    {
        if (!$this->require_method('POST')) return;
        if (!isset($this->db)) return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        if (!$this->enrollment_schema_ready()) {
            return $this->fail('Skema aktivasi installer belum dipasang.', 503, 'migration_required');
        }
        $payload = $this->read_json();
        if ($payload === FALSE) return;

        $villageCode = strtoupper(trim((string) (isset($payload['village_code']) ? $payload['village_code'] : '')));
        $enrollmentCode = $this->normalize_enrollment_code(isset($payload['enrollment_code']) ? $payload['enrollment_code'] : '');
        $deviceId = trim((string) (isset($payload['device_id']) ? $payload['device_id'] : ''));
        $appVersion = trim((string) (isset($payload['app_version']) ? $payload['app_version'] : ''));
        // Kode wilayah Indonesia memakai format 2.2.2.4 digit, misalnya
        // 95.01.03.2003.
        $rateScope = preg_match('/^[0-9]{2}(?:\.[0-9]{2}){2}\.[0-9]{4}$/', $villageCode) ? $villageCode : 'invalid';

        if (!$this->allow_enrollment_attempt($rateScope)) {
            return $this->fail('Terlalu banyak percobaan aktivasi. Tunggu beberapa menit lalu coba lagi.', 429, 'rate_limited');
        }

        if (!preg_match('/^[0-9]{2}(?:\.[0-9]{2}){2}\.[0-9]{4}$/', $villageCode)
            || !preg_match('/^SDW[A-HJ-NP-Z2-9]{16}$/', $enrollmentCode)
            || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $deviceId)
            || strlen($appVersion) > 50) {
            $this->record_failed_enrollment($rateScope);
            return $this->fail('Wilayah, kode aktivasi, atau identitas instalasi tidak valid.', 422, 'invalid_enrollment');
        }

        $codeHash = hash('sha256', $enrollmentCode);
        $deviceHash = hash('sha256', $deviceId);
        $now = date('Y-m-d H:i:s');

        if (!$this->db->trans_begin()) {
            return $this->fail('Layanan aktivasi sedang tidak tersedia. Silakan coba lagi.', 503, 'enrollment_unavailable');
        }
        $query = $this->db->query(
            "SELECT i.*, v.village_code, v.name AS village_name, v.district_code, v.district_name, v.regency_code, v.regency_name
             FROM village_installations i
             INNER JOIN village_tenants v ON v.id = i.village_id
             WHERE v.village_code = ? AND v.status = 'active'
               AND i.status = 'active' AND i.enrollment_code_hash = ?
             ORDER BY i.updated_at DESC, i.id DESC
            LIMIT 1 FOR UPDATE",
            array($villageCode, $codeHash)
        );
        if (!$query) {
            $dbError = $this->db->error();
            $this->db->trans_rollback();
            log_message('error', 'Gagal mencari kredensial enrollment: ' . json_encode($dbError));
            return $this->fail('Layanan aktivasi sedang tidak tersedia. Silakan coba lagi.', 503, 'enrollment_unavailable');
        }
        $row = $query->row_array();

        if (!$row) {
            $this->db->trans_rollback();
            $this->record_failed_enrollment($rateScope);
            return $this->fail('Kode aktivasi tidak cocok dengan kampung yang dipilih.', 401, 'invalid_enrollment');
        }
        if (!empty($row['enrollment_expires_at']) && strtotime((string) $row['enrollment_expires_at']) < time()) {
            $this->db->trans_rollback();
            $this->record_failed_enrollment($rateScope);
            return $this->fail('Kode aktivasi sudah kedaluwarsa. Minta kode baru kepada pengelola SmartDesa.', 410, 'enrollment_expired');
        }

        $alreadyUsed = !empty($row['enrollment_used_at']);
        if ($alreadyUsed && (empty($row['enrollment_device_hash']) || !hash_equals((string) $row['enrollment_device_hash'], $deviceHash))) {
            $this->db->trans_rollback();
            $this->record_failed_enrollment($rateScope);
            return $this->fail('Kode aktivasi sudah digunakan pada instalasi lain.', 409, 'enrollment_used');
        }

        $secret = $this->decrypt_secret(isset($row['sync_secret_encrypted']) ? $row['sync_secret_encrypted'] : '');
        if ($secret === NULL) {
            $this->db->trans_rollback();
            return $this->fail('Kredensial instalasi belum dapat dibaca.', 503, 'credentials_unavailable');
        }

        $update = array(
            'last_seen_at' => $now,
            'updated_at' => $now
        );
        if (!$alreadyUsed) {
            $update['enrollment_used_at'] = $now;
            $update['enrollment_device_hash'] = $deviceHash;
        }
        if ($appVersion !== '') $update['app_version'] = $appVersion;

        $updated = $this->db->where('id', $row['id'])->update('village_installations', $update);
        if (!$updated || !$this->db->trans_status()) {
            $this->db->trans_rollback();
            return $this->fail('Aktivasi belum dapat disimpan. Silakan coba lagi.', 503, 'enrollment_unavailable');
        }
        if (!$this->db->trans_commit()) {
            return $this->fail('Aktivasi belum dapat diselesaikan. Silakan coba lagi.', 503, 'enrollment_unavailable');
        }
        $this->clear_enrollment_attempts($rateScope);

        return $this->respond(array(
            'success' => TRUE,
            'message' => $alreadyUsed ? 'Aktivasi instalasi dipulihkan.' : 'SmartDesa berhasil dihubungkan ke layanan warga.',
            'installation' => array(
                'installation_code' => (string) $row['installation_code'],
                'secret' => $secret,
                'village' => array(
                    'code' => (string) $row['village_code'],
                    'name' => (string) $row['village_name'],
                    'district_code' => (string) $row['district_code'],
                    'district_name' => (string) $row['district_name'],
                    'regency_code' => (string) $row['regency_code'],
                    'regency_name' => (string) $row['regency_name']
                ),
                'enrolled_at' => $alreadyUsed ? (string) $row['enrollment_used_at'] : $now
            ),
            'server_time' => date('c')
        ));
    }

    private function enrollment_schema_ready()
    {
        return $this->db->table_exists('installation_enrollment_attempts')
            && $this->db->field_exists('enrollment_code_hash', 'village_installations')
            && $this->db->field_exists('enrollment_expires_at', 'village_installations')
            && $this->db->field_exists('enrollment_used_at', 'village_installations')
            && $this->db->field_exists('enrollment_device_hash', 'village_installations');
    }

    private function normalize_enrollment_code($value)
    {
        return preg_replace('/[^A-Z2-9]/', '', strtoupper(trim((string) $value)));
    }

    private function client_ip_hash($scope)
    {
        $ip = trim((string) $this->input->ip_address());
        return hash('sha256', 'smartdesa-enrollment|' . $ip . '|' . trim((string) $scope));
    }

    private function allow_enrollment_attempt($scope)
    {
        $this->db->where('attempted_at <', date('Y-m-d H:i:s', time() - 86400))->delete('installation_enrollment_attempts');
        $count = $this->db
            ->where('ip_hash', $this->client_ip_hash($scope))
            ->where('attempted_at >=', date('Y-m-d H:i:s', time() - 900))
            ->count_all_results('installation_enrollment_attempts');
        return (int) $count < 20;
    }

    private function record_failed_enrollment($scope)
    {
        $this->db->insert('installation_enrollment_attempts', array(
            'ip_hash' => $this->client_ip_hash($scope),
            'attempted_at' => date('Y-m-d H:i:s')
        ));
    }

    private function clear_enrollment_attempts($scope)
    {
        $this->db->where('ip_hash', $this->client_ip_hash($scope))->delete('installation_enrollment_attempts');
    }
}
