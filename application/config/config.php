<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$configuredUrl = getenv('APP_URL');
if ($configuredUrl) $config['base_url'] = rtrim($configuredUrl, '/') . '/';
else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $config['base_url'] = $scheme . '://' . $host . '/';
}
$config['index_page'] = '';
$config['uri_protocol'] = 'REQUEST_URI';
$config['url_suffix'] = '';
$config['language'] = 'english';
$config['charset'] = 'UTF-8';
$config['enable_hooks'] = FALSE;
$config['subclass_prefix'] = 'MY_';
$config['composer_autoload'] = FALSE;
$config['permitted_uri_chars'] = 'a-z 0-9~%.:_\-';
$config['enable_query_strings'] = FALSE;
$config['allow_get_array'] = TRUE;
$config['log_threshold'] = ENVIRONMENT === 'development' ? 1 : 1;
$config['log_path'] = '';
$appKey = trim((string) (getenv('APP_KEY') ?: ''));
if (ENVIRONMENT === 'production') {
    $appParts = parse_url(trim((string) getenv('APP_URL')));
    $originParts = parse_url(trim((string) getenv('WARGA_ALLOWED_ORIGIN')));
    $invalidKey = $appKey === '' || strlen($appKey) < 32 || stripos($appKey, 'ganti-dengan') !== FALSE || stripos($appKey, 'replace-with') !== FALSE || stripos($appKey, 'change-before') !== FALSE;
    $invalidAppUrl = !is_array($appParts) || strtolower(isset($appParts['scheme']) ? $appParts['scheme'] : '') !== 'https' || empty($appParts['host']);
    $invalidOrigin = !is_array($originParts) || strtolower(isset($originParts['scheme']) ? $originParts['scheme'] : '') !== 'https' || empty($originParts['host']) || (isset($originParts['path']) && rtrim($originParts['path'], '/') !== '') || isset($originParts['query']) || isset($originParts['fragment']);
    $storagePath = trim((string) getenv('PRIVATE_STORAGE_PATH'));
    $storageReal = $storagePath !== '' ? realpath($storagePath) : FALSE;
    $publicReal = realpath(FCPATH);
    $invalidStorage = $storageReal === FALSE || !is_dir($storageReal) || !is_readable($storageReal)
        || ($publicReal !== FALSE && strpos(rtrim($storageReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, rtrim($publicReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0);
    if ($invalidKey || getenv('API_DEMO_MODE') !== '0' || $invalidAppUrl || $invalidOrigin || $invalidStorage) {
        header('HTTP/1.1 503 Service Unavailable', TRUE, 503);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(array('success' => FALSE, 'error' => 'configuration_incomplete', 'message' => 'Konfigurasi API produksi belum lengkap.')));
    }
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$config['encryption_key'] = $appKey !== '' ? $appKey : hash('sha256', FCPATH . '|smartdesa-warga-api');
$config['csrf_protection'] = FALSE;
$config['compress_output'] = FALSE;
$config['time_reference'] = 'local';
$config['proxy_ips'] = '';
