<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Errors extends CI_Controller
{
    public function not_found()
    {
        $this->output->set_status_header(404)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode(array('success' => FALSE, 'error' => 'not_found', 'message' => 'Endpoint tidak ditemukan.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
