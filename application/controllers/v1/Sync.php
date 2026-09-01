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
        $this->touch_installation(TRUE);
        return $this->respond(array(
            'success' => TRUE,
            'installation' => $installation['installation_code'],
            'village' => array('code' => $installation['village_code'], 'name' => $installation['village_name']),
            'messages' => $messages,
            'server_time' => date('c')
        ));
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
        $this->touch_installation(TRUE);
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
        $this->touch_installation(TRUE);

        return $this->respond(array(
            'success' => TRUE,
            'accepted' => isset($result['accepted']) ? (int) $result['accepted'] : 0,
            'rejected' => isset($result['rejected']) ? (int) $result['rejected'] : 0,
            'results' => isset($result['results']) && is_array($result['results']) ? $result['results'] : array(),
            'server_time' => date('c')
        ));
    }
}
