<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Reset password akun PWA warga.
 *
 * OTP, token, email, dan alamat IP tidak disimpan dalam bentuk terbuka.
 * Pengiriman email didelegasikan ke SmartDesa pusat agar konfigurasi SMTP
 * tetap satu pintu pada Pengaturan Notifikasi Super Admin.
 */
class Password_reset_model extends CI_Model
{
    private $table = 'warga_password_reset_requests';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function schema_ready()
    {
        if (!$this->db->table_exists($this->table)) return FALSE;
        foreach (array('request_token_hash', 'user_id', 'purpose', 'email_hash', 'target_email_hash', 'target_phone_hash', 'ip_hash', 'otp_hash', 'status', 'attempts', 'expires_at') as $field) {
            if (!$this->db->field_exists($field, $this->table)) return FALSE;
        }
        return TRUE;
    }

    public function normalize_email($email)
    {
        $email = strtolower(trim((string) $email));
        return strlen($email) <= 180 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public function normalize_phone($phone)
    {
        $phone = trim((string) $phone);
        if ($phone === '') return '';
        if (!preg_match('/^[0-9+() .-]+$/', $phone)) return '';
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return preg_match('/^\+?[0-9]{8,15}$/', $phone) ? $phone : '';
    }

    public function request_code($email, $ip, $user_agent = '')
    {
        $email = $this->normalize_email($email);
        if ($email === '') return array('success' => FALSE, 'message' => 'Alamat email belum valid.');
        if (!$this->schema_ready()) return array('success' => FALSE, 'message' => 'Layanan reset password belum selesai diperbarui.', 'status' => 503);

        $email_hash = $this->value_hash('email|' . $email);
        $ip_hash = $this->value_hash('ip|' . $this->normalize_ip($ip));
        $now = date('Y-m-d H:i:s');
        $window = date('Y-m-d H:i:s', time() - 900);
        $cooldown = date('Y-m-d H:i:s', time() - 60);
        $this->db->where('created_at <', date('Y-m-d H:i:s', time() - 86400))->delete($this->table);

        $recent_email = (int) $this->db->where('email_hash', $email_hash)->where('created_at >=', $window)->count_all_results($this->table);
        $recent_ip = (int) $this->db->where('ip_hash', $ip_hash)->where('created_at >=', $window)->count_all_results($this->table);
        $cooling_down = (int) $this->db->where('email_hash', $email_hash)->where('created_at >=', $cooldown)->count_all_results($this->table) > 0;
        // Semua browser PWA diteruskan oleh satu server aplikasi, sehingga
        // batas IP harus cukup longgar untuk banyak desa. Perlindungan utama
        // tetap berada pada batas per email dan cooldown per tujuan.
        if ($recent_email >= 3 || $recent_ip >= 5000 || $cooling_down) {
            return array(
                'success' => FALSE,
                'message' => $cooling_down
                    ? 'Kode baru dapat diminta kembali setelah 60 detik.'
                    : 'Terlalu banyak permintaan reset. Silakan tunggu 15 menit.',
                'status' => 429
            );
        }

        $users = $this->db->select('id, name, email, password_hash')
            ->where(array('email' => $email, 'is_active' => 1))
            ->limit(2)->get('users')->result_array();
        $user = count($users) === 1 ? $users[0] : NULL;

        try {
            $request_token = bin2hex(random_bytes(32));
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            log_message('error', 'Pembuatan OTP PWA gagal: ' . $e->getMessage());
            return array('success' => FALSE, 'message' => 'Kode keamanan belum dapat dibuat.', 'status' => 503);
        }

        $row = array(
            'request_token_hash' => $this->value_hash('token|' . $request_token),
            'user_id' => $user ? (int) $user['id'] : NULL,
            'purpose' => 'password_reset',
            'email_hash' => $email_hash,
            'ip_hash' => $ip_hash,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'status' => $user ? 'pending' : 'decoy',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'created_at' => $now,
            'updated_at' => $now
        );
        if (!$this->db->insert($this->table, $row)) {
            return array('success' => FALSE, 'message' => 'Permintaan reset belum dapat disimpan.', 'status' => 503);
        }
        $request_id = (int) $this->db->insert_id();

        if ($user) {
            $delivery = $this->send_central_email($email, (string) $user['name'], $otp, 'password_reset');
            if (empty($delivery['success'])) {
                $this->db->where('id', $request_id)->update($this->table, array(
                    'status' => 'delivery_failed',
                    'updated_at' => date('Y-m-d H:i:s')
                ));
                log_message('error', 'Relay email reset PWA gagal: ' . (isset($delivery['message']) ? $delivery['message'] : 'unknown'));
            }
        }

        // Respons sengaja sama untuk email terdaftar dan tidak terdaftar.
        return array(
            'success' => TRUE,
            'request_token' => $request_token,
            'email_masked' => $this->mask_email($email),
            'expires_in' => 600,
            'resend_after' => 60,
            'message' => 'Jika email terdaftar, kode 6 digit akan dikirim oleh layanan pusat. Periksa Inbox, Spam, atau Promosi; email yang belum terdaftar tidak akan menerima kode.'
        );
    }

    /**
     * Start an authenticated account change. The API receives the current
     * password, but never stores it. The OTP is delivered to an email address
     * owned by the account (or the new email when changing it).
     */
    public function request_account_change($userId, $currentPassword, $purpose, $targetEmail, $targetPhone, $ip)
    {
        $userId = (int) $userId;
        $purpose = strtolower(trim((string) $purpose));
        $rawEmail = trim((string) $targetEmail);
        $rawPhone = trim((string) $targetPhone);
        $targetEmail = $this->normalize_email($targetEmail);
        $targetPhone = $this->normalize_phone($targetPhone);
        if ($userId < 1 || !in_array($purpose, array('contact', 'password'), TRUE)) {
            return array('success' => FALSE, 'message' => 'Permintaan keamanan akun tidak valid.', 'status' => 422);
        }
        if (!$this->schema_ready()) return array('success' => FALSE, 'message' => 'Layanan keamanan akun belum selesai diperbarui.', 'status' => 503);
        if ($purpose === 'contact' && (($rawEmail !== '' && $targetEmail === '') || ($rawPhone !== '' && $targetPhone === ''))) return array('success' => FALSE, 'message' => 'Email atau nomor telepon belum valid.', 'status' => 422);
        $user = $this->db->select('id,name,email,phone,password_hash')->where(array('id' => $userId, 'is_active' => 1))->limit(1)->get('users')->row_array();
        if (!$user || !password_verify((string) $currentPassword, (string) $user['password_hash'])) {
            return array('success' => FALSE, 'message' => 'Kata sandi saat ini tidak sesuai.', 'status' => 422);
        }
        $currentEmail = $this->normalize_email($user['email'] ?? '');
        if ($purpose === 'contact') {
            if ($targetEmail === '') return array('success' => FALSE, 'message' => 'Email aktif wajib diisi agar keamanan dan pemulihan akun tetap tersedia.', 'status' => 422);
            $destination = $targetEmail;
            if ($targetEmail !== '' || $targetPhone !== '') {
                $this->db->group_start();
                if ($targetEmail !== '') $this->db->where('email', $targetEmail)->or_where('username', $targetEmail);
                if ($targetPhone !== '') $this->db->or_where('phone', $targetPhone)->or_where('username', $targetPhone);
                $this->db->group_end()->where('id !=', $userId);
                if ($this->db->count_all_results('users') > 0) return array('success' => FALSE, 'message' => 'Email atau nomor telepon sudah digunakan akun lain.', 'status' => 409);
            }
        } else {
            $destination = $currentEmail;
            if ($destination === '') return array('success' => FALSE, 'message' => 'Tambahkan email akun terlebih dahulu agar kode keamanan dapat dikirim.', 'status' => 422);
            $targetEmail = $currentEmail;
            $targetPhone = $this->normalize_phone($user['phone'] ?? '');
        }
        $ipHash = $this->value_hash('ip|' . $this->normalize_ip($ip));
        $since = date('Y-m-d H:i:s', time() - 900);
        $recentUser = (int) $this->db->where('user_id', $userId)->where_in('purpose', array('contact_change', 'password_change'))->where('created_at >=', $since)->count_all_results($this->table);
        $recentIp = (int) $this->db->where('ip_hash', $ipHash)->where_in('purpose', array('contact_change', 'password_change'))->where('created_at >=', $since)->count_all_results($this->table);
        if ($recentUser >= 5 || $recentIp >= 5000) {
            return array('success' => FALSE, 'message' => 'Terlalu banyak permintaan kode. Silakan tunggu 15 menit.', 'status' => 429);
        }
        try {
            $requestToken = bin2hex(random_bytes(32));
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            return array('success' => FALSE, 'message' => 'Kode keamanan belum dapat dibuat.', 'status' => 503);
        }
        $now = date('Y-m-d H:i:s');
        $row = array(
            'request_token_hash' => $this->value_hash('token|' . $requestToken),
            'user_id' => $userId,
            'purpose' => $purpose . '_change',
            'email_hash' => $this->value_hash('email|' . $destination),
            'target_email_hash' => $this->value_hash('target-email|' . $targetEmail),
            'target_phone_hash' => $this->value_hash('target-phone|' . $targetPhone),
            'ip_hash' => $ipHash,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'status' => 'account_pending',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'created_at' => $now,
            'updated_at' => $now
        );
        if (!$this->db->insert($this->table, $row)) return array('success' => FALSE, 'message' => 'Permintaan keamanan belum dapat disimpan.', 'status' => 503);
        $requestId = (int) $this->db->insert_id();
        $sent = $this->send_central_email($destination, (string) $user['name'], $otp, 'account_change');
        if (empty($sent['success'])) {
            $this->db->where('id', $requestId)->update($this->table, array('status' => 'delivery_failed', 'updated_at' => date('Y-m-d H:i:s')));
            log_message('error', 'Relay email OTP perubahan akun gagal: ' . ($sent['message'] ?? 'unknown'));
            return array('success' => FALSE, 'message' => 'Kode verifikasi belum berhasil dikirim. Silakan coba lagi.', 'status' => 503);
        }
        return array('success' => TRUE, 'request_token' => $requestToken, 'email_masked' => $this->mask_email($destination), 'expires_in' => 600, 'resend_after' => 60, 'message' => 'Kode verifikasi telah dikirim ke email akun. Periksa Inbox, Spam, atau Promosi.');
    }

    public function complete_account_change($userId, $purpose, $requestToken, $otp, $targetEmail, $targetPhone, $newPassword = '')
    {
        $userId = (int) $userId;
        $purpose = strtolower(trim((string) $purpose));
        $requestToken = strtolower(trim((string) $requestToken));
        $otp = preg_replace('/\D+/', '', (string) $otp);
        $targetEmail = $this->normalize_email($targetEmail);
        $targetPhone = $this->normalize_phone($targetPhone);
        if ($userId < 1 || !in_array($purpose, array('contact', 'password'), TRUE) || !preg_match('/^[a-f0-9]{64}$/', $requestToken) || !preg_match('/^[0-9]{6}$/', $otp)) return array('success' => FALSE, 'message' => 'Kode verifikasi tidak valid.', 'status' => 422);
        if ($purpose === 'password' && (strlen((string) $newPassword) < 8 || strlen((string) $newPassword) > 72 || strpos((string) $newPassword, "\0") !== FALSE)) return array('success' => FALSE, 'message' => 'Kata sandi baru harus 8–72 karakter.', 'status' => 422);
        if (!$this->schema_ready() || !$this->db->trans_begin()) return array('success' => FALSE, 'message' => 'Layanan keamanan akun belum siap.', 'status' => 503);
        $row = $this->db->query("SELECT * FROM `{$this->table}` WHERE request_token_hash = ? AND user_id = ? AND purpose = ? AND status = 'account_pending' LIMIT 1 FOR UPDATE", array($this->value_hash('token|' . $requestToken), $userId, $purpose . '_change'))->row_array();
        if (!$row || strtotime((string) $row['expires_at']) < time()) {
            if ($row) $this->db->where('id', (int) $row['id'])->update($this->table, array('status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')));
            $this->db->trans_commit();
            return array('success' => FALSE, 'message' => 'Kode sudah kedaluwarsa atau tidak lagi berlaku.', 'status' => 410);
        }
        $attempts = (int) $row['attempts'];
        $max = max(1, (int) $row['max_attempts']);
        if (!password_verify($otp, (string) $row['otp_hash'])) {
            $attempts++;
            $this->db->where('id', (int) $row['id'])->update($this->table, array('attempts' => $attempts, 'status' => $attempts >= $max ? 'blocked' : 'account_pending', 'updated_at' => date('Y-m-d H:i:s')));
            $this->db->trans_commit();
            return array('success' => FALSE, 'message' => $attempts >= $max ? 'Kode salah lima kali. Minta kode baru.' : 'Kode belum benar. Sisa percobaan: ' . ($max - $attempts) . '.', 'status' => $attempts >= $max ? 429 : 422);
        }
        if (!hash_equals((string) $row['target_email_hash'], $this->value_hash('target-email|' . $targetEmail)) || !hash_equals((string) $row['target_phone_hash'], $this->value_hash('target-phone|' . $targetPhone))) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Data perubahan akun sudah berubah. Minta kode baru.', 'status' => 409);
        }
        $user = $this->db->select('id,email,phone,password_hash')->where(array('id' => $userId, 'is_active' => 1))->limit(1)->get('users')->row_array();
        if (!$user) { $this->db->trans_rollback(); return array('success' => FALSE, 'message' => 'Akun tidak tersedia.', 'status' => 410); }
        if ($purpose === 'contact') {
            if ($targetEmail === '') { $this->db->trans_rollback(); return array('success' => FALSE, 'message' => 'Email aktif wajib diisi agar keamanan dan pemulihan akun tetap tersedia.', 'status' => 422); }
            $this->db->group_start();
            if ($targetEmail !== '') $this->db->where('email', $targetEmail)->or_where('username', $targetEmail);
            if ($targetPhone !== '') $this->db->or_where('phone', $targetPhone)->or_where('username', $targetPhone);
            $this->db->group_end()->where('id !=', $userId);
            if ($this->db->count_all_results('users') > 0) { $this->db->trans_rollback(); return array('success' => FALSE, 'message' => 'Email atau nomor telepon sudah digunakan akun lain.', 'status' => 409); }
            $updated = $this->db->where(array('id' => $userId, 'is_active' => 1))->update('users', array('email' => $targetEmail !== '' ? $targetEmail : NULL, 'phone' => $targetPhone !== '' ? $targetPhone : NULL, 'updated_at' => date('Y-m-d H:i:s')));
            $message = 'Email dan nomor telepon berhasil diperbarui.';
        } else {
            if (password_verify((string) $newPassword, (string) $user['password_hash'])) { $this->db->trans_rollback(); return array('success' => FALSE, 'message' => 'Kata sandi baru harus berbeda dari kata sandi sebelumnya.', 'status' => 422); }
            $hash = password_hash((string) $newPassword, PASSWORD_DEFAULT);
            $this->db->set('password_hash', $hash)->set('updated_at', date('Y-m-d H:i:s'));
            if ($this->db->field_exists('session_version', 'users')) $this->db->set('session_version', 'session_version + 1', FALSE);
            $updated = $this->db->where(array('id' => $userId, 'is_active' => 1))->update('users');
            $message = 'Kata sandi berhasil diperbarui. Silakan masuk kembali.';
        }
        $used = $this->db->where(array('id' => (int) $row['id'], 'status' => 'account_pending'))->update($this->table, array('status' => 'used', 'verified_at' => date('Y-m-d H:i:s'), 'used_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')));
        if (!$updated || !$used || !$this->db->trans_status()) { $this->db->trans_rollback(); return array('success' => FALSE, 'message' => 'Perubahan akun belum dapat disimpan.', 'status' => 503); }
        $this->db->where('user_id', $userId)->where('id !=', (int) $row['id'])->where_in('status', array('account_pending', 'pending', 'decoy'))->update($this->table, array('status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')));
        if (!$this->db->trans_commit()) return array('success' => FALSE, 'message' => 'Perubahan akun belum dapat diselesaikan.', 'status' => 503);
        return array('success' => TRUE, 'message' => $message, 'email' => $targetEmail, 'phone' => $targetPhone);
    }

    public function complete($request_token, $otp, $new_password)
    {
        $request_token = strtolower(trim((string) $request_token));
        $otp = preg_replace('/\D+/', '', (string) $otp);
        $new_password = (string) $new_password;
        if (!preg_match('/^[a-f0-9]{64}$/', $request_token) || !preg_match('/^[0-9]{6}$/', $otp)) {
            return array('success' => FALSE, 'message' => 'Kode verifikasi tidak valid.', 'status' => 422);
        }
        if (strlen($new_password) < 8 || strlen($new_password) > 72 || strpos($new_password, "\0") !== FALSE) {
            return array('success' => FALSE, 'message' => 'Kata sandi baru harus 8–72 karakter.', 'status' => 422);
        }
        if (!$this->schema_ready()) return array('success' => FALSE, 'message' => 'Layanan reset password belum selesai diperbarui.', 'status' => 503);

        if (!$this->db->trans_begin()) {
            return array('success' => FALSE, 'message' => 'Reset password belum dapat dimulai.', 'status' => 503);
        }
        $token_hash = $this->value_hash('token|' . $request_token);
        $row = $this->db->query(
            "SELECT * FROM `{$this->table}` WHERE `request_token_hash` = ? AND `status` IN ('pending','decoy') LIMIT 1 FOR UPDATE",
            array($token_hash)
        )->row_array();
        if (!$row || strtotime((string) $row['expires_at']) < time()) {
            if ($row) $this->db->where('id', (int) $row['id'])->update($this->table, array('status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')));
            $this->db->trans_commit();
            return array('success' => FALSE, 'message' => 'Kode sudah kedaluwarsa atau tidak lagi berlaku.', 'status' => 410);
        }

        $attempts = (int) $row['attempts'];
        $max_attempts = max(1, (int) $row['max_attempts']);
        if (!password_verify($otp, (string) $row['otp_hash'])) {
            $attempts++;
            $blocked = $attempts >= $max_attempts;
            $this->db->where('id', (int) $row['id'])->update($this->table, array(
                'attempts' => $attempts,
                'status' => $blocked ? 'blocked' : (string) $row['status'],
                'updated_at' => date('Y-m-d H:i:s')
            ));
            $this->db->trans_commit();
            return array(
                'success' => FALSE,
                'message' => $blocked ? 'Kode salah lima kali. Minta kode baru.' : 'Kode belum benar. Sisa percobaan: ' . ($max_attempts - $attempts) . '.',
                'status' => $blocked ? 429 : 422
            );
        }

        $user = !empty($row['user_id'])
            ? $this->db->select('id, email, password_hash')->where(array('id' => (int) $row['user_id'], 'is_active' => 1))->limit(1)->get('users')->row_array()
            : NULL;
        $current_email = $user ? $this->normalize_email(isset($user['email']) ? $user['email'] : '') : '';
        if (!$user || $current_email === '' || !hash_equals((string) $row['email_hash'], $this->value_hash('email|' . $current_email))) {
            $this->db->where('id', (int) $row['id'])->update($this->table, array('status' => 'blocked', 'updated_at' => date('Y-m-d H:i:s')));
            $this->db->trans_commit();
            return array('success' => FALSE, 'message' => 'Akun tidak tersedia atau email akun sudah berubah.', 'status' => 410);
        }
        if (password_verify($new_password, (string) $user['password_hash'])) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Kata sandi baru harus berbeda dari kata sandi sebelumnya.', 'status' => 422);
        }

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        if (!is_string($new_hash) || $new_hash === '') {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Kata sandi baru belum dapat diamankan.', 'status' => 503);
        }
        $this->db->set('password_hash', $new_hash)->set('updated_at', date('Y-m-d H:i:s'));
        if ($this->db->field_exists('session_version', 'users')) {
            $this->db->set('session_version', 'session_version + 1', FALSE);
        }
        $updated = $this->db->where(array('id' => (int) $user['id'], 'is_active' => 1))->update('users');
        $used = $this->db->where(array('id' => (int) $row['id'], 'status' => 'pending'))->update($this->table, array(
            'status' => 'used',
            'verified_at' => date('Y-m-d H:i:s'),
            'used_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ));
        if (!$updated || !$used || !$this->db->trans_status()) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'message' => 'Kata sandi baru belum dapat disimpan.', 'status' => 503);
        }
        $this->db->where('user_id', (int) $user['id'])
            ->where('id !=', (int) $row['id'])
            ->where_in('status', array('pending', 'decoy'))
            ->update($this->table, array('status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')));
        if (!$this->db->trans_commit()) {
            return array('success' => FALSE, 'message' => 'Kata sandi baru belum dapat diselesaikan.', 'status' => 503);
        }
        return array('success' => TRUE, 'message' => 'Kata sandi berhasil diperbarui. Silakan masuk kembali.');
    }

    private function send_central_email($email, $name, $otp, $purpose = 'password_reset')
    {
        $url = trim((string) getenv('SMARTDESA_NOTIFICATION_RELAY_URL'));
        if ($url === '') $url = 'https://smartdesa.mediaverse.co.id/password_reset_api/warga_otp';
        $key = trim((string) getenv('WARGA_MONITOR_API_KEY'));
        $secret = trim((string) getenv('WARGA_MONITOR_API_SECRET'));
        $parts = parse_url($url);
        if (!function_exists('curl_init')
            || filter_var($url, FILTER_VALIDATE_URL) === FALSE
            || !is_array($parts) || empty($parts['host'])
            || (ENVIRONMENT === 'production' && strtolower((string) (isset($parts['scheme']) ? $parts['scheme'] : '')) !== 'https')
            || !preg_match('/^[A-Za-z0-9._-]{16,128}$/', $key)
            || strlen($secret) < 32 || hash_equals($key, $secret)
            || $this->placeholder_credential($key . $secret)) {
            return array('success' => FALSE, 'message' => 'Konfigurasi relay notifikasi pusat belum lengkap.');
        }

        $body = json_encode(array(
            'email' => $email,
            'name' => $this->clean_text($name, 160),
            'account' => $email,
            'otp' => $otp,
            'expires_minutes' => 10,
            'purpose' => $purpose === 'account_change' ? 'account_change' : 'password_reset',
            'source' => 'warga-pwa'
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) return array('success' => FALSE, 'message' => 'Payload email tidak dapat dibuat.');
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return array('success' => FALSE, 'message' => 'Nonce relay tidak dapat dibuat.');
        }
        $timestamp = (string) time();
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/password_reset_api/warga_otp';
        $canonical = $timestamp . "\n" . $nonce . "\nPOST\n" . $path . "\n" . $body;
        $signature = hash_hmac('sha256', $canonical, $secret);
        $timeout = max(5, min(30, (int) (getenv('SMARTDESA_NOTIFICATION_RELAY_TIMEOUT') ?: 20)));

        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_1_1') ? CURL_HTTP_VERSION_1_1 : 0,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
                'Cache-Control: no-store',
                'X-SmartDesa-Monitor-Key: ' . $key,
                'X-SmartDesa-Monitor-Timestamp: ' . $timestamp,
                'X-SmartDesa-Monitor-Nonce: ' . $nonce,
                'X-SmartDesa-Monitor-Signature: ' . $signature
            ),
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === FALSE || $error !== '') return array('success' => FALSE, 'message' => 'Server notifikasi pusat tidak dapat dihubungi.');
        $decoded = json_decode((string) $response, TRUE);
        if (!is_array($decoded) || $status < 200 || $status >= 300 || empty($decoded['success'])) {
            return array('success' => FALSE, 'message' => 'Server notifikasi pusat menolak pengiriman.');
        }
        return array('success' => TRUE);
    }

    private function mask_email($email)
    {
        list($local, $domain) = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));
        return $visible . str_repeat('*', max(3, strlen($local) - strlen($visible))) . '@' . $domain;
    }

    private function value_hash($value)
    {
        return hash_hmac('sha256', (string) $value, (string) getenv('APP_KEY'));
    }

    private function normalize_ip($ip)
    {
        $ip = trim((string) $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    private function clean_text($value, $max_length)
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags((string) $value));
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max_length, 'UTF-8') : substr($value, 0, $max_length);
    }

    private function placeholder_credential($value)
    {
        return preg_match('/(?:replace|change|ganti)[_\s-]*(?:with|before|dengan)|example|placeholder/i', (string) $value) === 1;
    }
}
