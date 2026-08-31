<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Errors extends MY_Controller
{
    public function not_found()
    {
        return $this->respond(array('success' => FALSE, 'error' => 'not_found', 'message' => 'Endpoint tidak ditemukan.'), 404);
    }
}
