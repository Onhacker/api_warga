<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php $payload = array('success' => FALSE, 'error' => 'not_found', 'message' => 'Endpoint tidak ditemukan.'); ?>
<?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
