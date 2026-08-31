<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Health extends MY_Controller
{
    public function index()
    {
        $database = 'demo';
        if (getenv('API_DEMO_MODE') !== '1') {
            if (!isset($this->db) || !$this->db->conn_id || !$this->db->query('SELECT 1')) return $this->respond(array('success' => FALSE, 'service' => 'SmartDesa Warga API', 'status' => 'degraded'), 503);
            $database = 'ready';
        }
        return $this->respond(array('success' => TRUE, 'service' => 'SmartDesa Warga API', 'status' => 'ready', 'database' => $database, 'time' => date('c')));
    }
}
