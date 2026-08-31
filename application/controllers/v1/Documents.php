<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Mengirim berkas pendukung melalui API bertanda tangan.
 *
 * Berkas tidak pernah disajikan dari public_html. Instalasi hanya dapat
 * mengambil berkas yang memang dimiliki oleh desa yang terautentikasi.
 */
class Documents extends MY_Controller
{
    public function show($id = '')
    {
        if (!$this->require_method('GET')) return;
        $installation = $this->authenticate_installation();
        if (!$installation) return;

        $id = rawurldecode((string) $id);
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id)) {
            $this->fail('Berkas tidak ditemukan.', 404, 'not_found');
            return;
        }

        // Mode demo tidak memiliki berkas fisik untuk disajikan.
        if (getenv('API_DEMO_MODE') === '1') {
            $this->fail('Berkas tidak ditemukan.', 404, 'not_found');
            return;
        }

        $root = realpath(trim((string) getenv('PRIVATE_STORAGE_PATH')));
        if ($root === FALSE || !is_dir($root) || !is_readable($root)) {
            $this->fail('Penyimpanan berkas API belum siap.', 503, 'storage_unavailable');
            return;
        }

        $this->load->model('Sync_model');
        $document = $this->Sync_model->document_for_installation($installation, $id);
        if (!$document) {
            $this->fail('Berkas tidak ditemukan.', 404, 'not_found');
            return;
        }

        $path = realpath((string) $document['storage_path']);
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($path === FALSE || !is_file($path) || !is_readable($path)
            || strpos($path, $rootPrefix) !== 0) {
            $this->fail('Berkas tidak ditemukan.', 404, 'not_found');
            return;
        }

        $size = filesize($path);
        if ($size === FALSE || $size < 1 || $size > 8 * 1024 * 1024) {
            $this->fail('Ukuran berkas tidak dapat dilayani.', 404, 'not_found');
            return;
        }

        $mime = $this->detect_mime($path);
        $allowed = array('image/jpeg', 'image/png', 'application/pdf');
        if (!in_array($mime, $allowed, TRUE)) {
            $this->fail('Jenis berkas tidak diizinkan.', 415, 'unsupported_file_type');
            return;
        }

        $body = file_get_contents($path);
        if (!is_string($body) || $body === '') {
            $this->fail('Berkas belum dapat dibaca.', 404, 'not_found');
            return;
        }

        $filename = $this->safe_filename(
            isset($document['original_name']) ? $document['original_name'] : '',
            $mime,
            $id
        );
        $this->output
            ->set_status_header(200)
            ->set_content_type($mime)
            ->set_header('Content-Disposition: inline; filename="' . $filename . '"')
            ->set_header('Content-Length: ' . strlen($body))
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_output($body);
    }

    private function detect_mime($path)
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                return is_string($mime) ? strtolower(trim($mime)) : '';
            }
        }
        if (function_exists('mime_content_type')) {
            return strtolower(trim((string) mime_content_type($path)));
        }
        return '';
    }

    private function safe_filename($name, $mime, $id)
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $name = trim((string) $name, '._-');
        $extensions = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf'
        );
        $extension = isset($extensions[$mime]) ? $extensions[$mime] : 'bin';
        if ($name === '') $name = 'berkas-' . substr(str_replace('-', '', $id), 0, 12);
        if (!preg_match('/\.' . preg_quote($extension, '/') . '$/i', $name)) {
            $name .= '.' . $extension;
        }
        return substr($name, 0, 180);
    }
}
