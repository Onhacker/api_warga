<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Sync extends MY_Controller
{
    public function pull()
    {
        if (!$this->require_method('POST')) return;
        $installation = $this->authenticate_installation();
        if (!$installation) return;
        $payload = $this->read_json();
        if ($payload === FALSE) return;
        $limit = isset($payload['limit']) ? (int) $payload['limit'] : 50;
        $this->load->model('Sync_model');
        $messages = $this->Sync_model->pull($installation, $limit);
        $residentDirectory = $this->Sync_model->resident_directory_state($installation);
        $staffAccounts = $this->Sync_model->staff_accounts_state($installation);
        $branding = $this->branding_state($installation);
        $this->touch_installation(TRUE, isset($payload['app_version']) ? $payload['app_version'] : '');
        return $this->respond(array(
            'success' => TRUE,
            'installation' => $installation['installation_code'],
            'village' => array('code' => $installation['village_code'], 'name' => $installation['village_name']),
            'messages' => $messages,
            'sync_state' => array(
                'resident_directory' => $residentDirectory,
                'staff_accounts' => $staffAccounts,
                'branding' => $branding
            ),
            'server_time' => date('c')
        ));
    }

    private function branding_state(array $installation)
    {
        $villageCode = strtoupper(trim((string) ($installation['village_code'] ?? '')));
        $tenantCode = preg_match('/^([0-9]{2}\.[0-9]{2})(?:\.|$)/', $villageCode, $match)
            ? $match[1] : 'default';

        $this->load->model('Public_branding_model');
        $result = $this->Public_branding_model->status($tenantCode);
        if (empty($result['success']) || empty($result['branding']) || !is_array($result['branding'])) {
            return array(
                'ready' => FALSE,
                'tenant_code' => $tenantCode,
                'updated_at' => ''
            );
        }

        return array(
            'ready' => !empty($result['branding']['region_labels_managed']),
            'tenant_code' => (string) ($result['branding']['tenant_code'] ?? $tenantCode),
            'resolved_tenant' => (string) ($result['resolved_tenant'] ?? $tenantCode),
            'nama_sistem' => (string) ($result['branding']['nama_sistem'] ?? ''),
            'kepanjangan' => (string) ($result['branding']['kepanjangan'] ?? ''),
            'tagline' => (string) ($result['branding']['tagline'] ?? ''),
            'bentuk_lembaga' => (string) ($result['branding']['bentuk_lembaga'] ?? ''),
            'bentuk_kecamatan' => (string) ($result['branding']['bentuk_kecamatan'] ?? ''),
            'region_labels_managed' => !empty($result['branding']['region_labels_managed']) ? 1 : 0,
            'updated_at' => (string) ($result['updated_at'] ?? '')
        );
    }

    public function ack()
    {
        if (!$this->require_method('POST')) return;
        $installation = $this->authenticate_installation();
        if (!$installation) return;
        $payload = $this->read_json();
        if ($payload === FALSE) return;
        if (!isset($payload['messages']) || !is_array($payload['messages']) || count($payload['messages']) > 100) return $this->fail('Daftar tanda terima tidak valid.', 422, 'invalid_ack');
        $this->load->model('Sync_model');
        $processed = $this->Sync_model->acknowledge($installation, $payload['messages']);
        $this->touch_installation(TRUE, isset($payload['app_version']) ? $payload['app_version'] : '');
        return $this->respond(array('success' => TRUE, 'processed' => $processed, 'server_time' => date('c')));
    }

    public function push()
    {
        if (!$this->require_method('POST')) return;
        $installation = $this->authenticate_installation();
        if (!$installation) return;
        $payload = $this->read_json();
        if ($payload === FALSE) return;
        if (!isset($payload['messages']) || !is_array($payload['messages']) || count($payload['messages']) > 100) return $this->fail('Daftar perubahan tidak valid.', 422, 'invalid_push');
        $this->load->model('Sync_model');
        $result = $this->Sync_model->enqueue($installation, $payload['messages']);
        if (!is_array($result)) {
            $result = array('accepted' => (int) $result, 'rejected' => 0, 'results' => array());
        }
        $this->touch_installation(TRUE, isset($payload['app_version']) ? $payload['app_version'] : '');

        return $this->respond(array(
            'success' => TRUE,
            'accepted' => isset($result['accepted']) ? (int) $result['accepted'] : 0,
            'rejected' => isset($result['rejected']) ? (int) $result['rejected'] : 0,
            'results' => isset($result['results']) && is_array($result['results']) ? $result['results'] : array(),
            'server_time' => date('c')
        ));
    }
}
