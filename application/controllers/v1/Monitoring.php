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

    public function villages()
    {
        if (!$this->require_method('POST')) return;
        if (!$this->authenticate_monitoring()) return;
        if (!isset($this->db) || empty($this->db->conn_id)) {
            return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        }

        $payload = $this->read_json();
        if ($payload === FALSE) return;
        $validation = $this->validate_payload($payload);
        if ($validation !== TRUE) return $this->fail($validation, 422, 'invalid_scope');

        $this->load->model('Monitoring_model');
        $result = $this->Monitoring_model->village_options($payload);
        if (empty($result['success'])) {
            $error = isset($result['error']) ? (string) $result['error'] : 'invalid_scope';
            $status = in_array($error, array('service_unavailable', 'migration_required'), TRUE) ? 503 : 422;
            return $this->fail(isset($result['message']) ? $result['message'] : 'Daftar kampung belum dapat dimuat.', $status, $error);
        }

        return $this->respond(array(
            'success' => TRUE,
            'count' => count($result['villages']),
            'villages' => $result['villages'],
            'server_time' => date('c')
        ));
    }

    public function rebind()
    {
        if (!$this->require_method('POST')) return;
        if (!$this->authenticate_monitoring()) return;
        if (!isset($this->db) || empty($this->db->conn_id)) {
            return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        }

        $payload = $this->read_json();
        if ($payload === FALSE) return;
        $validation = $this->validate_rebind_payload($payload);
        if ($validation !== TRUE) return $this->fail($validation, 422, 'invalid_rebind');

        $this->load->model('Monitoring_model');
        $scope = $this->Monitoring_model->village_options($payload);
        if (empty($scope['success'])) {
            $error = isset($scope['error']) ? (string) $scope['error'] : 'invalid_scope';
            $status = in_array($error, array('service_unavailable', 'migration_required'), TRUE) ? 503 : 422;
            return $this->fail(isset($scope['message']) ? $scope['message'] : 'Wilayah rebind belum dapat diverifikasi.', $status, $error);
        }

        $allowed = array();
        foreach ($scope['villages'] as $village) {
            $code = strtoupper(trim((string) (isset($village['village_code']) ? $village['village_code'] : '')));
            if ($code !== '') $allowed[$code] = TRUE;
        }
        $sourceCode = strtoupper(trim((string) $payload['source_village_code']));
        $targetCode = strtoupper(trim((string) $payload['target_village_code']));
        if (!isset($allowed[$sourceCode]) || !isset($allowed[$targetCode])) {
            return $this->fail('Kampung sumber atau tujuan tidak termasuk kabupaten yang diizinkan.', 403, 'invalid_scope');
        }

        $this->load->model('Installation_rebind_model');
        $result = $this->Installation_rebind_model->move(array(
            'operation_id' => strtolower(trim((string) $payload['operation_id'])),
            'source_village_code' => $sourceCode,
            'target_village_code' => $targetCode,
            'reason' => $this->clean_text($payload['reason'], 500),
            'actor' => $this->clean_text($payload['actor'], 160)
        ));
        if (empty($result['success'])) {
            $error = isset($result['error']) ? (string) $result['error'] : 'rebind_failed';
            if (in_array($error, array('service_unavailable', 'migration_required', 'credentials_unavailable'), TRUE)) $status = 503;
            elseif (in_array($error, array('binding_conflict', 'multiple_installations'), TRUE)) $status = 409;
            elseif (in_array($error, array('source_not_found', 'target_not_found'), TRUE)) $status = 404;
            else $status = 422;
            return $this->fail(isset($result['message']) ? $result['message'] : 'Pindah kampung belum dapat diselesaikan.', $status, $error);
        }

        return $this->respond(array(
            'success' => TRUE,
            'message' => isset($result['message']) ? $result['message'] : 'Kredensial layanan warga telah disiapkan ulang.',
            'operation_id' => $result['operation_id'],
            'already_applied' => !empty($result['already_applied']),
            'source' => isset($result['source']) ? $result['source'] : array(),
            'target' => isset($result['target']) ? $result['target'] : array(),
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

    private function validate_rebind_payload(array $payload)
    {
        $scopeValidation = $this->validate_payload($payload);
        if ($scopeValidation !== TRUE) return $scopeValidation;

        $operationId = isset($payload['operation_id']) && is_scalar($payload['operation_id'])
            ? strtolower(trim((string) $payload['operation_id'])) : '';
        $source = isset($payload['source_village_code']) && is_scalar($payload['source_village_code'])
            ? strtoupper(trim((string) $payload['source_village_code'])) : '';
        $target = isset($payload['target_village_code']) && is_scalar($payload['target_village_code'])
            ? strtoupper(trim((string) $payload['target_village_code'])) : '';
        $reason = isset($payload['reason']) && is_scalar($payload['reason']) ? $this->clean_text($payload['reason'], 501) : '';
        $actor = isset($payload['actor']) && is_scalar($payload['actor']) ? $this->clean_text($payload['actor'], 161) : '';

        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $operationId)) {
            return 'Identitas operasi rebind tidak valid.';
        }
        if (!preg_match('/^[0-9]{2}(?:\.[0-9]{2}){2}\.[0-9]{4}$/', $source)
            || !preg_match('/^[0-9]{2}(?:\.[0-9]{2}){2}\.[0-9]{4}$/', $target)) {
            return 'Kode kampung sumber atau tujuan tidak valid.';
        }
        if (hash_equals($source, $target)) return 'Kampung tujuan harus berbeda dari kampung sumber.';
        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($reasonLength < 8 || $reasonLength > 500) return 'Alasan pemindahan wajib diisi minimal 8 karakter.';
        $actorLength = function_exists('mb_strlen') ? mb_strlen($actor, 'UTF-8') : strlen($actor);
        if ($actorLength < 1 || $actorLength > 160) return 'Identitas operator rebind tidak valid.';
        return TRUE;
    }

    private function clean_text($value, $max)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        if (function_exists('mb_substr')) return mb_substr($value, 0, (int) $max, 'UTF-8');
        return substr($value, 0, (int) $max);
    }
}
