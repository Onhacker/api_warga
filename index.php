<?php
/**
 * SmartDesa Warga central API front controller.
 * This application is deployed separately from the citizen PWA.
 */

if (!function_exists('warga_api_load_env')) {
    function warga_api_load_env($path)
    {
        if (!is_readable($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) $value = substr($value, 1, -1);
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

warga_api_load_env(__DIR__ . '/.env');
define('ENVIRONMENT', strtolower(trim(getenv('APP_ENV') ?: 'production')));
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Makassar');
if (ENVIRONMENT === 'development') {
    error_reporting(-1);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

$system_path = realpath(__DIR__ . '/system');
$application_folder = realpath(__DIR__ . '/application');
if ($system_path === false || $application_folder === false) {
    http_response_code(503);
    exit('Runtime API tidak lengkap.');
}
define('SELF', pathinfo(__FILE__, PATHINFO_BASENAME));
define('BASEPATH', rtrim($system_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('SYSDIR', basename(BASEPATH));
define('APPPATH', rtrim($application_folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('VIEWPATH', APPPATH . 'views' . DIRECTORY_SEPARATOR);
require_once BASEPATH . 'core/CodeIgniter.php';
