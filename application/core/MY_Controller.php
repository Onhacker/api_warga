<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Controller extends CI_Controller
{
    protected $installation = NULL;
    protected $rawBody = '';
    protected $requestId = '';

    public function __construct()
    {
        parent::__construct();
        $this->requestId = api_uuid();
        $this->output->set_header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $this->output->set_header('Pragma: no-cache');
        $this->output->set_header('X-Request-ID: ' . $this->requestId);
        $this->apply_cors();
        if (strtoupper($this->input->method(TRUE)) === 'OPTIONS') {
            $this->output->set_status_header(204)->set_content_type('application/json', 'utf-8')->set_output('');
            exit;
        }
        $limit = max(1024, (int) (getenv('API_MAX_BODY_BYTES') ?: 2097152));
        if (preg_match('#/v1/requests/[a-f0-9-]{36}/official-(?:document|html)/?$#i', $this->request_path())) {
            $documentLimit = (int) (getenv('API_OFFICIAL_DOCUMENT_MAX_BYTES') ?: 8388608);
            $limit = max($limit, min(16 * 1024 * 1024, $documentLimit));
        }
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > $limit) {
            $this->fail('Ukuran permintaan terlalu besar.', 413, 'payload_too_large');
            exit;
        }
        $stream = fopen('php://input', 'rb');
        $this->rawBody = is_resource($stream) ? (string) stream_get_contents($stream, $limit + 1) : '';
        if (is_resource($stream)) fclose($stream);
        if (strlen($this->rawBody) > $limit) {
            $this->fail('Ukuran permintaan terlalu besar.', 413, 'payload_too_large');
            exit;
        }
    }

    protected function apply_cors()
    {
        $allowed = trim((string) getenv('WARGA_ALLOWED_ORIGIN'));
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        if ($allowed !== '' && $origin !== '' && hash_equals($allowed, $origin)) {
            // OPTIONS exits before CodeIgniter flushes its output object.
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-SmartDesa-Installation, X-SmartDesa-Timestamp, X-SmartDesa-Nonce, X-SmartDesa-Signature, X-SmartDesa-Auto-Key, X-SmartDesa-Auto-Timestamp, X-SmartDesa-Auto-Nonce, X-SmartDesa-Auto-Signature, X-SmartDesa-Document-SHA256, X-SmartDesa-Document-Name, X-SmartDesa-Document-Reference, X-SmartDesa-Actor-Name');
            header('Vary: Origin');
        }
    }

    protected function respond(array $payload, $status = 200)
    {
        $payload['request_id'] = $this->requestId;
        return $this->output->set_status_header((int) $status)->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function fail($message, $status = 400, $publicCode = 'bad_request')
    {
        $payload = array('success' => FALSE, 'error' => $publicCode, 'message' => $message);
        if (ENVIRONMENT === 'development') $payload['debug'] = array('method' => $this->input->method(TRUE), 'path' => $this->request_path());
        $this->respond($payload, $status);
        return FALSE;
    }

    protected function request_path()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    protected function read_json()
    {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim((string) $_SERVER['CONTENT_TYPE'])) : '';
        if ($this->rawBody !== '' && strpos($contentType, 'application/json') !== 0) {
            $this->fail('Content-Type harus application/json.', 415, 'unsupported_media_type');
            return FALSE;
        }
        if ($this->rawBody === '') return array();
        $payload = json_decode($this->rawBody, TRUE);
        if (!is_array($payload)) {
            $this->fail('Format JSON tidak valid.', 400, 'invalid_json');
            return FALSE;
        }
        return $payload;
    }

    protected function require_method($method)
    {
        $method = strtoupper((string) $method);
        if (strtoupper($this->input->method(TRUE)) === $method) return TRUE;
        $this->output->set_header('Allow: ' . $method . ', OPTIONS');
        $this->fail('Metode permintaan tidak diizinkan.', 405, 'method_not_allowed');
        return FALSE;
    }

    protected function authenticate_installation()
    {
        $code = api_header('X-SmartDesa-Installation');
        $timestamp = api_header('X-SmartDesa-Timestamp');
        $nonce = api_header('X-SmartDesa-Nonce');
        $signature = strtolower(api_header('X-SmartDesa-Signature'));
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $code) || !preg_match('/^\d{10}$/', $timestamp) || !preg_match('/^[A-Za-z0-9._-]{16,128}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            $this->fail('Header autentikasi sinkronisasi belum lengkap.', 401, 'authentication_required');
            return FALSE;
        }
        $ttl = max(60, (int) (getenv('API_SIGNATURE_TTL') ?: 300));
        if (abs(time() - (int) $timestamp) > $ttl) {
            $this->fail('Waktu permintaan sudah kedaluwarsa.', 401, 'stale_request');
            return FALSE;
        }
        $secret = NULL;
        $installation = NULL;
        if (getenv('API_DEMO_MODE') === '1') {
            $expectedCode = trim((string) getenv('API_DEMO_INSTALLATION'));
            $expectedSecret = (string) getenv('API_DEMO_SECRET');
            if ($expectedCode === '' || $expectedSecret === '' || !hash_equals($expectedCode, $code)) {
                $this->fail('Instalasi tidak dikenali.', 401, 'invalid_credentials');
                return FALSE;
            }
            $secret = $expectedSecret;
            $installation = array('id' => 'demo-installation', 'installation_code' => $code, 'village_id' => '00000000-0000-4000-8000-000000000001', 'village_code' => '95.01.03.2003', 'village_name' => 'Kampung Araboda');
        } else {
            if (!isset($this->db)) {
                $this->fail('Database API belum tersedia.', 503, 'service_unavailable');
                return FALSE;
            }
            $installation = $this->db->select('i.*, v.village_code, v.name AS village_name')->from('village_installations i')->join('village_tenants v', 'v.id=i.village_id')->where(array('i.installation_code' => $code, 'i.status' => 'active', 'v.status' => 'active'))->get()->row_array();
            if (!$installation || empty($installation['sync_secret_encrypted'])) {
                $this->fail('Instalasi tidak dikenali atau belum dikonfigurasi.', 401, 'invalid_credentials');
                return FALSE;
            }
            $secret = $this->decrypt_secret($installation['sync_secret_encrypted']);
            if ($secret === NULL) {
                $this->fail('Kredensial instalasi belum dapat dibaca.', 503, 'credentials_unavailable');
                return FALSE;
            }
        }
        $canonical = $timestamp . "\n" . $nonce . "\n" . strtoupper($this->input->method(TRUE)) . "\n" . $this->request_path() . "\n" . $this->rawBody;
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            $this->fail('Tanda tangan permintaan tidak sesuai.', 401, 'invalid_signature');
            return FALSE;
        }
        if (getenv('API_DEMO_MODE') !== '1') {
            $this->db->where('expires_at <', date('Y-m-d H:i:s'))->delete('api_request_nonces');
            $inserted = $this->db->insert('api_request_nonces', array('installation_id' => $installation['id'], 'nonce' => $nonce, 'expires_at' => date('Y-m-d H:i:s', time() + $ttl)));
            if (!$inserted) {
                $this->fail('Permintaan duplikat ditolak.', 409, 'replayed_request');
                return FALSE;
            }
            $this->db->where('id', $installation['id'])->update('village_installations', array('last_seen_at' => date('Y-m-d H:i:s')));
        }
        $this->installation = $installation;
        return $installation;
    }

    /**
     * Catat aktivitas instalasi tanpa pernah mengubah scope desanya.
     * last_seen_at dicatat saat autentikasi; last_sync_at hanya dicatat
     * setelah endpoint sinkronisasi berhasil memproses permintaan.
     */
    protected function touch_installation($synced = FALSE)
    {
        if (getenv('API_DEMO_MODE') === '1' || empty($this->installation['id']) || !isset($this->db)) return;
        $now = date('Y-m-d H:i:s');
        $data = array('last_seen_at' => $now);
        if ($synced) $data['last_sync_at'] = $now;
        $this->db->where('id', $this->installation['id'])->update('village_installations', $data);
    }

    protected function decrypt_secret($encoded)
    {
        $packed = base64_decode((string) $encoded, TRUE);
        if ($packed === FALSE || strlen($packed) < 29 || !function_exists('openssl_decrypt')) return NULL;
        $iv = substr($packed, 0, 12);
        $tag = substr($packed, 12, 16);
        $ciphertext = substr($packed, 28);
        $key = hash('sha256', (string) getenv('APP_KEY'), TRUE);
        $secret = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'smartdesa-warga-api');
        return is_string($secret) && $secret !== '' ? $secret : NULL;
    }
}
