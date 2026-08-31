<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('api_header')) {
    function api_header($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return trim((string) $_SERVER[$key]);
        if (function_exists('getallheaders')) foreach ((array) getallheaders() as $header => $value) if (strcasecmp($header, $name) === 0) return trim((string) $value);
        return '';
    }
}

if (!function_exists('api_uuid')) {
    function api_uuid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
