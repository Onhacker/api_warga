<?php
// Integration checks for rebind on a uniquely named disposable database.
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__, 2);
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
require APPPATH . 'helpers/api_helper.php';
require APPPATH . 'models/Installation_rebind_model.php';

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

function encrypted_secret($secret, $appKey)
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', hash('sha256', $appKey, TRUE), OPENSSL_RAW_DATA, $iv, $tag, 'smartdesa-warga-api');
    if ($ciphertext === FALSE || strlen($tag) !== 16) throw new RuntimeException('Fixture secret cannot be encrypted.');
    return base64_encode($iv . $tag . $ciphertext);
}

function decrypted_secret($encoded, $appKey)
{
    $packed = base64_decode((string) $encoded, TRUE);
    if ($packed === FALSE || strlen($packed) < 29) return NULL;
    $secret = openssl_decrypt(substr($packed, 28), 'aes-256-gcm', hash('sha256', $appKey, TRUE), OPENSSL_RAW_DATA, substr($packed, 0, 12), substr($packed, 12, 16), 'smartdesa-warga-api');
    return is_string($secret) ? $secret : NULL;
}

$socket = getenv('SMARTDESA_TEST_DB_SOCKET') ?: NULL;
$host = $socket ? 'localhost' : (getenv('SMARTDESA_TEST_DB_HOST') ?: '127.0.0.1');
$user = getenv('SMARTDESA_TEST_DB_USER') ?: 'root';
$password = getenv('SMARTDESA_TEST_DB_PASS') ?: '';
$port = (int) (getenv('SMARTDESA_TEST_DB_PORT') ?: 3306);
$admin = new mysqli($host, $user, $password, '', $port, $socket);
if ($admin->connect_errno) { fwrite(STDERR, "Test database unavailable.\n"); exit(1); }

$database = 'sdw_rebind_test_' . bin2hex(random_bytes(8));
$created = FALSE;
$db = NULL;
$exitCode = 0;
$appKey = str_repeat('smartdesa-rebind-test-key-', 2);
putenv('APP_KEY=' . $appKey);

try {
    if (!$admin->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException($admin->error);
    }
    $created = TRUE;
    $admin->select_db($database);
    sql_batch($admin, file_get_contents($root . '/database/schema.sql'));
    check($admin->query('DROP TABLE installation_rebind_audit'), 'audit table can be recreated from migration');
    $migration = file_get_contents($root . '/database/migrations/021_installation_rebind_audit.sql');
    sql_batch($admin, $migration);
    sql_batch($admin, $migration);
    check($admin->query("SHOW TABLES LIKE 'installation_rebind_audit'")->num_rows === 1, 'migration 021 is repeatable');

    $db = DB(array(
        'hostname' => $socket ?: $host,
        'username' => $user,
        'password' => $password,
        'database' => $database,
        'dbdriver' => 'mysqli',
        'port' => $port,
        'pconnect' => FALSE,
        'db_debug' => FALSE,
        'char_set' => 'utf8mb4',
        'dbcollat' => 'utf8mb4_unicode_ci',
        'save_queries' => TRUE
    ), TRUE);
    $GLOBALS['test_ci'] = (object) array('db' => $db);

    $codes = array('95.01.01.2001', '95.01.01.2002');
    $villages = array();
    $installations = array();
    $oldSecrets = array();
    foreach ($codes as $index => $code) {
        $villageId = api_uuid();
        $installationId = api_uuid();
        $secret = 'fixture-secret-' . $index . '-' . str_repeat('x', 32);
        $villages[] = $villageId;
        $installations[] = $installationId;
        $oldSecrets[] = $secret;
        check($db->insert('village_tenants', array(
            'id' => $villageId,
            'province_code' => '95',
            'province_name' => 'Papua Pegunungan',
            'regency_code' => '95.01',
            'regency_name' => 'Jayawijaya',
            'district_code' => '95.01.01',
            'district_name' => 'Test',
            'village_code' => $code,
            'name' => 'Kampung Test ' . ($index + 1)
        )), 'test village ' . ($index + 1) . ' created');
        check($db->insert('village_installations', array(
            'id' => $installationId,
            'village_id' => $villageId,
            'installation_code' => 'SDW-REBIND-' . ($index + 1),
            'sync_key_hash' => hash('sha256', 'key-' . $index),
            'sync_secret_hash' => hash('sha256', $secret),
            'sync_secret_encrypted' => encrypted_secret($secret, $appKey),
            'enrollment_code_hash' => hash('sha256', 'enrollment-' . $index),
            'enrollment_expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'enrollment_used_at' => date('Y-m-d H:i:s'),
            'enrollment_device_hash' => hash('sha256', 'device-' . $index),
            'enrollment_hardware_hash' => hash('sha256', 'hardware-' . $index),
            'app_version' => '1.0.25',
            'last_seen_at' => date('Y-m-d H:i:s'),
            'last_sync_at' => date('Y-m-d H:i:s')
        )), 'test installation ' . ($index + 1) . ' created');
        check($db->insert('api_request_nonces', array(
            'installation_id' => $installationId,
            'nonce' => 'nonce-' . $index,
            'expires_at' => date('Y-m-d H:i:s', time() + 300)
        )), 'old nonce ' . ($index + 1) . ' created');
        check($db->insert('sync_messages', array(
            'id' => api_uuid(),
            'village_id' => $villageId,
            'installation_id' => $installationId,
            'aggregate_type' => 'test',
            'aggregate_id' => 'aggregate-' . $index,
            'direction' => 'local_to_cloud',
            'operation' => 'upsert',
            'payload_json' => '{}',
            'idempotency_key' => 'rebind-test-' . $index
        )), 'existing village queue ' . ($index + 1) . ' created');
    }

    $model = new Installation_rebind_model();
    $operationId = api_uuid();
    $result = $model->move(array(
        'operation_id' => $operationId,
        'source_village_code' => $codes[0],
        'target_village_code' => $codes[1],
        'reason' => 'Pengujian pindah kampung',
        'actor' => 'Super Admin Test'
    ));
    check(!empty($result['success']) && empty($result['already_applied']), 'first rebind succeeds');
    check(empty($result['secret']) && empty($result['source']['secret']) && empty($result['target']['secret']), 'response never exposes credentials');

    $after = array();
    foreach ($installations as $index => $installationId) {
        $row = $db->where('id', $installationId)->get('village_installations')->row_array();
        $after[] = $row;
        $newSecret = decrypted_secret($row['sync_secret_encrypted'], $appKey);
        check(is_string($newSecret) && $newSecret !== $oldSecrets[$index], 'secret ' . ($index + 1) . ' rotated and decryptable');
        check(hash_equals($row['sync_secret_hash'], hash('sha256', $newSecret)), 'secret hash ' . ($index + 1) . ' matches encrypted value');
        check($row['enrollment_used_at'] === NULL && $row['enrollment_device_hash'] === NULL && $row['enrollment_hardware_hash'] === NULL, 'enrollment ' . ($index + 1) . ' reset');
        check($row['installation_code'] === 'SDW-REBIND-' . ($index + 1) && $row['village_id'] === $villages[$index], 'installation scope ' . ($index + 1) . ' remains unchanged');
    }
    check($db->count_all('api_request_nonces') === 0, 'old authenticated nonces revoked');
    check($db->count_all('sync_messages') === 2, 'existing village data remains untouched');
    check($db->where('operation_id', $operationId)->count_all_results('installation_rebind_audit') === 1, 'one audit row stored');

    $hashesAfterFirst = array($after[0]['sync_secret_hash'], $after[1]['sync_secret_hash']);
    $retry = $model->move(array(
        'operation_id' => $operationId,
        'source_village_code' => $codes[0],
        'target_village_code' => $codes[1],
        'reason' => 'Pengujian pindah kampung',
        'actor' => 'Super Admin Test'
    ));
    check(!empty($retry['success']) && !empty($retry['already_applied']), 'same operation retry is idempotent');
    check($db->where('id', $installations[0])->get('village_installations')->row_array()['sync_secret_hash'] === $hashesAfterFirst[0]
        && $db->where('id', $installations[1])->get('village_installations')->row_array()['sync_secret_hash'] === $hashesAfterFirst[1], 'idempotent retry does not rotate secrets again');
    check($db->where('operation_id', $operationId)->count_all_results('installation_rebind_audit') === 1, 'idempotent retry does not duplicate audit');

    $conflict = $model->move(array(
        'operation_id' => $operationId,
        'source_village_code' => $codes[1],
        'target_village_code' => $codes[0],
        'reason' => 'Operasi berbeda dengan ID sama',
        'actor' => 'Super Admin Test'
    ));
    check(empty($conflict['success']) && $conflict['error'] === 'binding_conflict', 'operation ID cannot be reused for another move');

    $duplicateSecret = 'duplicate-target-' . str_repeat('z', 32);
    check($db->insert('village_installations', array(
        'id' => api_uuid(),
        'village_id' => $villages[1],
        'installation_code' => 'SDW-REBIND-DUPLICATE',
        'sync_key_hash' => hash('sha256', 'duplicate-key'),
        'sync_secret_hash' => hash('sha256', $duplicateSecret),
        'sync_secret_encrypted' => encrypted_secret($duplicateSecret, $appKey)
    )), 'duplicate active installation fixture created');
    $duplicate = $model->move(array(
        'operation_id' => api_uuid(),
        'source_village_code' => $codes[0],
        'target_village_code' => $codes[1],
        'reason' => 'Harus ditolak karena instalasi ganda',
        'actor' => 'Super Admin Test'
    ));
    check(empty($duplicate['success']) && $duplicate['error'] === 'multiple_installations', 'multiple active installations are rejected');
    check($db->where('id', $installations[0])->get('village_installations')->row_array()['sync_secret_hash'] === $hashesAfterFirst[0], 'failed rebind rolls back credential changes');

    echo "OK: $checks installation rebind checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n" . ($db ? $db->last_query() . "\n" : '') . $error->getTraceAsString() . "\n");
    $exitCode = 1;
} finally {
    if ($db) $db->close();
    if ($created) $admin->query("DROP DATABASE `$database`");
    $admin->close();
}

exit($exitCode);
