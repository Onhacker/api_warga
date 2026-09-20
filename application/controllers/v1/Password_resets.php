<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Password_resets extends MY_Controller
{
    public function request_code()
    {
        if (!$this->require_method('POST')) return;
        if (!isset($this->db)) return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        $payload = $this->read_json();
        if ($payload === FALSE) return;

        $this->load->model('Password_reset_model');
        $email = $this->Password_reset_model->normalize_email(isset($payload['email']) ? $payload['email'] : '');
        if ($email === '') return $this->fail('Masukkan alamat email yang valid.', 422, 'invalid_email');

        $result = $this->Password_reset_model->request_code(
            $email,
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            isset($payload['tenant_code']) ? $payload['tenant_code'] : 'default'
        );
        if (empty($result['success'])) {
            return $this->fail(
                isset($result['message']) ? $result['message'] : 'Permintaan reset belum dapat diproses.',
                isset($result['status']) ? (int) $result['status'] : 503,
                isset($result['status']) && (int) $result['status'] === 429 ? 'rate_limited' : 'reset_unavailable'
            );
        }

        return $this->respond(array(
            'success' => TRUE,
            'message' => $result['message'],
            'request_token' => $result['request_token'],
            'email_masked' => $result['email_masked'],
            'expires_in' => $result['expires_in'],
            'resend_after' => $result['resend_after']
        ));
    }

    public function complete()
    {
        if (!$this->require_method('POST')) return;
        if (!isset($this->db)) return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        $payload = $this->read_json();
        if ($payload === FALSE) return;

        $this->load->model('Password_reset_model');
        $result = $this->Password_reset_model->complete(
            isset($payload['request_token']) ? $payload['request_token'] : '',
            isset($payload['otp']) ? $payload['otp'] : '',
            isset($payload['new_password']) ? $payload['new_password'] : '',
            isset($payload['tenant_code']) ? $payload['tenant_code'] : 'default'
        );
        if (empty($result['success'])) {
            $status = isset($result['status']) ? (int) $result['status'] : 422;
            return $this->fail(
                isset($result['message']) ? $result['message'] : 'Reset password belum berhasil.',
                $status,
                $status === 429 ? 'rate_limited' : ($status === 410 ? 'reset_expired' : 'reset_rejected')
            );
        }

        return $this->respond(array('success' => TRUE, 'message' => $result['message']));
    }

    public function request_account_change()
    {
        if (!$this->require_method('POST')) return;
        if (!isset($this->db)) return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        $payload = $this->read_json();
        if ($payload === FALSE) return;
        $this->load->model('Password_reset_model');
        $result = $this->Password_reset_model->request_account_change(
            isset($payload['account_id']) ? $payload['account_id'] : 0,
            isset($payload['current_password']) ? $payload['current_password'] : '',
            isset($payload['purpose']) ? $payload['purpose'] : '',
            isset($payload['target_email']) ? $payload['target_email'] : '',
            isset($payload['target_phone']) ? $payload['target_phone'] : '',
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            isset($payload['tenant_code']) ? $payload['tenant_code'] : 'default'
        );
        if (empty($result['success'])) {
            $status = isset($result['status']) ? (int) $result['status'] : 422;
            return $this->fail($result['message'], $status, $status === 429 ? 'rate_limited' : 'account_change_rejected');
        }
        return $this->respond($result);
    }

    public function complete_account_change()
    {
        if (!$this->require_method('POST')) return;
        if (!isset($this->db)) return $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
        $payload = $this->read_json();
        if ($payload === FALSE) return;
        $this->load->model('Password_reset_model');
        $result = $this->Password_reset_model->complete_account_change(
            isset($payload['account_id']) ? $payload['account_id'] : 0,
            isset($payload['purpose']) ? $payload['purpose'] : '',
            isset($payload['request_token']) ? $payload['request_token'] : '',
            isset($payload['otp']) ? $payload['otp'] : '',
            isset($payload['target_email']) ? $payload['target_email'] : '',
            isset($payload['target_phone']) ? $payload['target_phone'] : '',
            isset($payload['new_password']) ? $payload['new_password'] : '',
            isset($payload['tenant_code']) ? $payload['tenant_code'] : 'default'
        );
        if (empty($result['success'])) {
            $status = isset($result['status']) ? (int) $result['status'] : 422;
            return $this->fail($result['message'], $status, $status === 429 ? 'rate_limited' : 'account_change_rejected');
        }
        return $this->respond($result);
    }
}
