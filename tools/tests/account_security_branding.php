<?php
// Integration checks for account OTP completion and centrally published branding.
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__, 2);
$pwa = getenv('SMARTDESA_TEST_PWA_PATH') ?: dirname($root) . '/smartdesa-warga';
if (!is_file($pwa . '/database/migrations/025_account_security_branding.sql')) {
    fwrite(STDERR, "Set SMARTDESA_TEST_PWA_PATH to the PWA source tree.\n");
    exit(1);
}

define('BASEPATH', $root . '/system/');
define('APPPATH', $root . '/application/');
define('ENVIRONMENT', 'testing');
error_reporting(E_ALL & ~E_DEPRECATED);
mysqli_report(MYSQLI_REPORT_OFF);

function log_message($level, $message) {}
function is_php($version) { return version_compare(PHP_VERSION, $version, '>='); }
function show_error($message) { throw new RuntimeException(is_array($message) ? implode(' ', $message) : $message); }
function &get_instance() { return $GLOBALS['test_ci']; }

require BASEPATH . 'core/Model.php';
require BASEPATH . 'database/DB.php';
require APPPATH . 'models/Password_reset_model.php';
require APPPATH . 'models/Public_branding_model.php';

$checks = 0;
function check($condition, $label)
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo "PASS $label\n";
}
function sql_batch($db, $sql)
{
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do {
        if ($result = $db->store_result()) $result->free();
        if (!$db->more_results()) break;
        if (!$db->next_result()) throw new RuntimeException($db->error);
    } while (TRUE);
}
function secure_hash($value)
{
    return hash_hmac('sha256', (string) $value, (string) getenv('APP_KEY'));
}
function pending_change_row($userId, $purpose, $token, $otp, $email, $phone, $ipSuffix)
{
    $now = date('Y-m-d H:i:s');
    return array(
        'request_token_hash' => secure_hash('token|' . $token),
        'user_id' => (int) $userId,
        'purpose' => $purpose . '_change',
        'email_hash' => secure_hash('email|' . $email),
        'target_email_hash' => secure_hash('target-email|' . $email),
        'target_phone_hash' => secure_hash('target-phone|' . $phone),
        'ip_hash' => secure_hash('ip|127.0.0.' . (int) $ipSuffix),
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'status' => 'account_pending',
        'attempts' => 0,
        'max_attempts' => 5,
        'expires_at' => date('Y-m-d H:i:s', time() + 600),
        'created_at' => $now,
        'updated_at' => $now
    );
}

$socket = getenv('SMARTDESA_TEST_DB_SOCKET') ?: NULL;
$host = $socket ? 'localhost' : (getenv('SMARTDESA_TEST_DB_HOST') ?: '127.0.0.1');
$user = getenv('SMARTDESA_TEST_DB_USER') ?: 'root';
$password = getenv('SMARTDESA_TEST_DB_PASS') ?: '';
$port = (int) (getenv('SMARTDESA_TEST_DB_PORT') ?: 3306);
$admin = new mysqli($host, $user, $password, '', $port, $socket);
if ($admin->connect_errno) { fwrite(STDERR, "Test database unavailable.\n"); exit(1); }

$database = 'sdw_account_test_' . bin2hex(random_bytes(8));
$created = FALSE;
$db = NULL;
$exitCode = 0;
putenv('APP_KEY=' . str_repeat('account-security-test-key-', 2));

try {
    if (!$admin->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $created = TRUE;
    $admin->select_db($database);
    sql_batch($admin, file_get_contents($root . '/database/schema.sql'));
    sql_batch($admin, file_get_contents($root . '/database/migrations/024_password_reset.sql'));
    $apiMigration = file_get_contents($root . '/database/migrations/025_account_security_branding.sql');
    $pwaMigration = file_get_contents($pwa . '/database/migrations/025_account_security_branding.sql');
    check($apiMigration === $pwaMigration, 'API and PWA use the exact same account security migration');
    sql_batch($admin, $apiMigration);
    sql_batch($admin, $apiMigration);
    $apiTenantMigration = file_get_contents($root . '/database/migrations/026_multi_tenant_branding.sql');
    $pwaTenantMigration = file_get_contents($pwa . '/database/migrations/026_multi_tenant_branding.sql');
    check($apiTenantMigration === $pwaTenantMigration, 'API and PWA use the exact same multi-tenant branding migration');
    sql_batch($admin, $apiTenantMigration);
    sql_batch($admin, $apiTenantMigration);

    $db = DB(array(
        'hostname' => $socket ?: $host, 'username' => $user, 'password' => $password,
        'database' => $database, 'dbdriver' => 'mysqli', 'port' => $port,
        'pconnect' => FALSE, 'db_debug' => FALSE, 'char_set' => 'utf8mb4',
        'dbcollat' => 'utf8mb4_unicode_ci', 'save_queries' => TRUE
    ), TRUE);
    $GLOBALS['test_ci'] = (object) array(
        'db' => $db,
        'load' => new class { public function database() {} }
    );

    $db->insert('roles', array('name' => 'Warga', 'slug' => 'warga'));
    $roleId = (int) $db->insert_id();
    $jayawijayaVillageId = '11111111-1111-4111-8111-111111111111';
    $otherVillageId = '22222222-2222-4222-8222-222222222222';
    $db->insert('village_tenants', array(
        'id' => $jayawijayaVillageId, 'province_code' => '95', 'province_name' => 'Papua Pegunungan',
        'regency_code' => '95.01', 'regency_name' => 'Jayawijaya', 'district_code' => '95.01.01',
        'district_name' => 'Wamena', 'village_code' => '95.01.01.2001', 'name' => 'Kampung Uji', 'status' => 'active'
    ));
    $db->insert('village_tenants', array(
        'id' => $otherVillageId, 'province_code' => '91', 'province_name' => 'Papua',
        'regency_code' => '91.01', 'regency_name' => 'Kabupaten Lain', 'district_code' => '91.01.01',
        'district_name' => 'Distrik Lain', 'village_code' => '91.01.01.2001', 'name' => 'Kampung Lain', 'status' => 'active'
    ));
    $db->insert('users', array(
        'role_id' => $roleId,
        'village_id' => $jayawijayaVillageId,
        'name' => 'Pengguna Uji',
        'username' => 'warga_account_test',
        'email' => 'lama@example.test',
        'phone' => '081200000001',
        'password_hash' => password_hash('PasswordLama!123', PASSWORD_DEFAULT),
        'is_active' => 1
    ));
    $userId = (int) $db->insert_id();

    $security = new Password_reset_model();
    check($security->schema_ready(), 'account security schema is ready after repeatable migration');

    $contactToken = bin2hex(random_bytes(32));
    $contactOtp = '314159';
    $newEmail = 'baru@example.test';
    $newPhone = '081299998888';
    check($db->insert('warga_password_reset_requests', pending_change_row($userId, 'contact', $contactToken, $contactOtp, $newEmail, $newPhone, 1)), 'contact OTP fixture stored');
    $wrongTenant = $security->complete_account_change($userId, 'contact', $contactToken, $contactOtp, $newEmail, $newPhone, '', '91.01');
    check(empty($wrongTenant['success']), 'account OTP is rejected by a different regency tenant');
    $contact = $security->complete_account_change($userId, 'contact', $contactToken, $contactOtp, $newEmail, $newPhone, '', '95.01');
    check(!empty($contact['success']), 'valid OTP completes contact change');
    $saved = $db->where('id', $userId)->get('users')->row_array();
    check($saved['email'] === $newEmail && $saved['phone'] === $newPhone, 'new email and phone are persisted on the account');
    check((int) $db->where(array('email' => 'lama@example.test', 'is_active' => 1))->count_all_results('users') === 0, 'old email is no longer used by password recovery');
    check(empty($security->complete_account_change($userId, 'contact', $contactToken, $contactOtp, $newEmail, $newPhone)['success']), 'account OTP cannot be reused');

    $passwordToken = bin2hex(random_bytes(32));
    $passwordOtp = '271828';
    check($db->insert('warga_password_reset_requests', pending_change_row($userId, 'password', $passwordToken, $passwordOtp, $newEmail, $newPhone, 2)), 'password OTP fixture stored');
    $changed = $security->complete_account_change($userId, 'password', $passwordToken, $passwordOtp, $newEmail, $newPhone, 'PasswordBaru!456', '95.01');
    check(!empty($changed['success']), 'valid OTP completes password change');
    $saved = $db->where('id', $userId)->get('users')->row_array();
    check(password_verify('PasswordBaru!456', $saved['password_hash']), 'new password hash verifies');
    check((int) $saved['session_version'] === 2, 'password change invalidates previous sessions');

    for ($i = 3; $i <= 5; $i++) {
        $token = bin2hex(random_bytes(32));
        $row = pending_change_row($userId, 'contact', $token, '123456', $newEmail, $newPhone, $i);
        $row['status'] = 'used';
        check($db->insert('warga_password_reset_requests', $row), 'rate-limit history fixture ' . $i . ' stored');
    }
    $limited = $security->request_account_change($userId, 'PasswordBaru!456', 'contact', 'dibatasi@example.test', $newPhone, '203.0.113.99', '95.01');
    check(empty($limited['success']) && (int) ($limited['status'] ?? 0) === 429, 'distributed attempts remain bounded per account');

    $branding = new Public_branding_model();
    $published = $branding->publish(array(
        'tenant_code' => '95.01',
        'tenant_name' => 'Jayawijaya',
        'nama_sistem' => '<b>SIDAPULIK Papua</b>',
        'kepanjangan' => 'Sistem Informasi Pelayanan Publik',
        'tagline' => 'Melayani warga dengan cepat'
    ));
    check(!empty($published['success']), 'central branding publication succeeds');
    $status = $branding->status('95.01');
    check(($status['branding']['nama_sistem'] ?? '') === 'SIDAPULIK Papua', 'published branding is sanitized and readable');
    check(($status['branding']['tagline'] ?? '') === 'Melayani warga dengan cepat', 'published tagline is readable by the PWA');
    check(($status['branding']['tenant_code'] ?? '') === '95.01', 'branding is stored under the requested regency tenant');
    $otherPublished = $branding->publish(array(
        'tenant_code' => '91.01',
        'tenant_name' => 'Kabupaten Lain',
        'nama_sistem' => 'LAYANAN LAIN',
        'kepanjangan' => 'Layanan Kabupaten Lain',
        'tagline' => 'Tagline tenant lain'
    ));
    check(!empty($otherPublished['success']), 'second regency branding publication succeeds');
    check(($branding->status('91.01')['branding']['nama_sistem'] ?? '') === 'LAYANAN LAIN', 'second regency reads its own branding');
    check(($branding->status('95.01')['branding']['nama_sistem'] ?? '') === 'SIDAPULIK Papua', 'second regency does not overwrite the first branding');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $exitCode = 1;
} finally {
    if ($db && method_exists($db, 'close')) $db->close();
    if ($created) {
        $admin->select_db('mysql');
        $admin->query("DROP DATABASE IF EXISTS `$database`");
    }
    $admin->close();
}

if ($exitCode === 0) echo 'OK: ' . $checks . " account security and branding checks passed.\n";
exit($exitCode);
