<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Endpoint privat untuk dashboard monitoring server SmartDesa.
 * Tidak menerima kredensial instalasi dan tidak mengembalikan data pribadi.
 */
class Monitoring extends MY_Controller
{
    public function summary()
    {
        if (!$this->require_method('POST')) return;
        if (!$this->authenticate_monitoring()) return;
        $db = $this->db;
        if (!is_object($db) || empty($db->conn_id)) {
            return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        }

        $payload = $this->read_json();
        if ($payload === FALSE) return;
        $validation = $this->validate_payload($payload);
        if ($validation !== TRUE) return $this->fail($validation, 422, 'invalid_scope');

        $this->load->model('Monitoring_model');
        $result = $this->Monitoring_model->summary($payload);
        if (empty($result['success'])) {
            $error = isset($result['error']) ? (string) $result['error'] : 'invalid_scope';
            $status = in_array($error, array('service_unavailable', 'migration_required'), TRUE) ? 503 : 422;
            return $this->fail(isset($result['message']) ? $result['message'] : 'Data monitoring belum dapat dimuat.', $status, $error);
        }

        return $this->respond(array(
            'success' => TRUE,
            'recordsTotal' => (int) $result['recordsTotal'],
            'recordsFiltered' => (int) $result['recordsFiltered'],
            'villages' => isset($result['villages']) && is_array($result['villages']) ? $result['villages'] : array(),
            'summary' => isset($result['summary']) && is_array($result['summary']) ? $result['summary'] : array(),
            'regencies' => isset($result['regencies']) && is_array($result['regencies']) ? $result['regencies'] : array(),
            'server_time' => date('c')
        ));
    }

    private function validate_payload(array $payload)
    {
        if (!isset($payload['regencies']) || !is_array($payload['regencies'])
            || count($payload['regencies']) < 1 || count($payload['regencies']) > 500) {
            return 'Daftar kabupaten monitoring tidak valid.';
        }
        if (isset($payload['selected_regency']) && $payload['selected_regency'] !== NULL
            && !is_array($payload['selected_regency']) && !is_scalar($payload['selected_regency'])) {
            return 'Filter kabupaten tidak valid.';
        }
        if (isset($payload['search'])) {
            if (!is_scalar($payload['search'])) return 'Kata pencarian tidak valid.';
            $searchLength = function_exists('mb_strlen') ? mb_strlen((string) $payload['search'], 'UTF-8') : strlen((string) $payload['search']);
            if ($searchLength > 100) return 'Kata pencarian terlalu panjang.';
        }
        if (isset($payload['start']) && (!is_numeric($payload['start']) || (int) $payload['start'] < 0 || (int) $payload['start'] > 1000000)) return 'Posisi halaman tidak valid.';
        if (isset($payload['length']) && (!is_numeric($payload['length']) || (int) $payload['length'] < 1 || (int) $payload['length'] > 100)) return 'Jumlah data per halaman tidak valid.';
        if (isset($payload['order_dir'])) {
            if (!is_scalar($payload['order_dir'])
                || !in_array(strtolower((string) $payload['order_dir']), array('asc', 'desc'), TRUE)) {
                return 'Arah pengurutan tidak valid.';
            }
        }
        return TRUE;
    }
}
