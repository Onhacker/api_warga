<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$route['default_controller'] = 'v1/health';
$route['404_override'] = 'errors/not_found';
$route['translate_uri_dashes'] = FALSE;
$route['v1/health'] = 'v1/health/index';
$route['v1/sync/pull'] = 'v1/sync/pull';
$route['v1/sync/push'] = 'v1/sync/push';
$route['v1/sync/ack'] = 'v1/sync/ack';
$route['v1/documents/(:any)'] = 'v1/documents/show/$1';
