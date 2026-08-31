<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$autoload['packages'] = array();
$autoload['libraries'] = getenv('API_DEMO_MODE') === '1' ? array() : array('database');
$autoload['drivers'] = array();
$autoload['helper'] = array('api');
$autoload['config'] = array();
$autoload['language'] = array();
$autoload['model'] = array();
