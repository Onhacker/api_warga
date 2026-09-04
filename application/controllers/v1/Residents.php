<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Public resident verification used only during citizen registration.
 * The endpoint receives identity data over HTTPS, compares HMACs against the
 * directory published by the matching village, and never returns an NIK/KK.
 */
class Residents extends MY_Controller
{
    public function verify()
    {
        if (!$this->require_method('POST')) return;
        if (!isset($this->db)) return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');

        $this->load->model('Sync_model');
        if (!$this->Sync_model->ensure_resident_schema()) {
            return $this->fail('Data penduduk desa belum siap di layanan warga.', 503, 'resident_directory_unavailable');
        }

        $payload = $this->read_json();
        if ($payload === FALSE) return;

        if (!$this->within_rate_limit()) {
            return $this->fail('Terlalu banyak percobaan verifikasi. Silakan tunggu beberapa menit.', 429, 'rate_limited');
        }

        $villageCode = strtoupper(trim((string) (isset($payload['village_code']) ? $payload['village_code'] : '')));
        $nik = $this->digits(isset($payload['nik']) ? $payload['nik'] : '');
        $kk = $this->digits(isset($payload['kk']) ? $payload['kk'] : '');
        $name = $this->clean_name(isset($payload['name']) ? $payload['name'] : '');
        if (!preg_match('/^[0-9]{2}(?:\.[0-9]{2}){2}\.[0-9]{4}$/', $villageCode)
            || !preg_match('/^[0-9]{16}$/', $nik)
            || !preg_match('/^[0-9]{16}$/', $kk)
            || $name === '') {
            return $this->fail('NIK, No. KK, nama, atau wilayah belum valid.', 422, 'invalid_identity');
        }

        $village = $this->db->where(array('village_code' => $villageCode, 'status' => 'active'))
            ->limit(1)->get('village_tenants')->row_array();
        if (!$village) {
            return $this->fail('Data identitas tidak cocok dengan kampung yang dipilih.', 422, 'resident_not_found');
        }

        // A new village is not searchable until its first full snapshot arrives.
        // An empty, completed snapshot is valid and still uses normal matching.
        $snapshot = $this->db->select('id')
            ->where(array('village_id' => $village['id'], 'finalized' => 1))
            ->limit(1)->get('village_resident_snapshots')->row_array();
        if (!$snapshot) {
            return $this->fail('Data penduduk kampung belum selesai tersinkron ke layanan warga.', 503, 'resident_directory_unavailable');
        }

        $row = $this->db->where(array(
                'village_id' => $village['id'],
                'nik_hash' => $this->identity_hash($nik),
                'kk_hash' => $this->identity_hash($kk),
                'status' => 'active'
            ))
            ->limit(1)->get('village_resident_directory')->row_array();
        if (!$row || !hash_equals((string) $row['name_hash'], $this->identity_hash($name))) {
            return $this->fail('NIK, No. KK, dan nama tidak sesuai dengan data penduduk kampung yang dipilih.', 422, 'resident_not_found');
        }

        return $this->respond(array(
            'success' => TRUE,
            'resident' => array(
                'source_key' => (string) $row['local_citizen_key'],
                'display_name' => (string) $row['display_name'],
                'birth_date' => !empty($row['birth_date']) ? (string) $row['birth_date'] : NULL,
                'gender' => !empty($row['gender']) ? (string) $row['gender'] : NULL
            ),
            'message' => 'Data penduduk berhasil diverifikasi.'
        ));
    }

    private function within_rate_limit()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '0.0.0.0';
        $hash = hash_hmac('sha256', $ip, (string) getenv('APP_KEY'));
        $since = date('Y-m-d H:i:s', time() - 900);
        $this->db->where('attempted_at <', $since)->delete('resident_verification_attempts');
        $count = (int) $this->db->where(array('ip_hash' => $hash))->where('attempted_at >=', $since)->count_all_results('resident_verification_attempts');
        if ($count >= 20) return FALSE;
        $this->db->insert('resident_verification_attempts', array('ip_hash' => $hash, 'attempted_at' => date('Y-m-d H:i:s')));
        return TRUE;
    }

    private function digits($value)
    {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function clean_name($value)
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function identity_hash($value)
    {
        return hash_hmac('sha256', (string) $value, (string) getenv('APP_KEY'));
    }
}
