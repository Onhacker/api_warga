<?php
// Integration test for sibling API/PWA source trees and a disposable database.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
$pwa = getenv('SMARTDESA_TEST_PWA_PATH') ?: dirname($root) . '/smartdesa-warga';
if (!is_file($pwa . '/application/models/Request_model.php')) {
    fwrite(STDERR, "Set SMARTDESA_TEST_PWA_PATH to the PWA source tree.\n");
    exit(1);
}
define('BASEPATH', $root . '/system/');
define('APPPATH', $root . '/application/');
define('FCPATH', $pwa . '/');
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
require APPPATH . 'models/Sync_model.php';
// Exercise the real verification controller without starting an HTTP server.
class MY_Controller
{
    public $payload = array();
    protected function require_method($method) { return TRUE; }
    protected function read_json() { return $this->payload; }
    protected function respond($data) { return $data; }
    protected function fail($message, $status, $code)
    {
        return array('success' => FALSE, 'error' => $code, 'http_status' => $status);
    }
}
require APPPATH . 'controllers/v1/Residents.php';
require $pwa . '/application/helpers/warga_helper.php';
require $pwa . '/application/models/Auth_model.php';
require $pwa . '/application/models/Request_model.php';
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return FALSE;
});
putenv('API_DEMO_MODE=0');
putenv('WARGA_DEMO_MODE=0');
putenv('APP_KEY=' . str_repeat('test-only-', 5));
$_FILES = array();
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
function push_message($model, $installation, $type, $operation, $payload, $aggregate = NULL, $key = NULL)
{
    return $model->enqueue($installation, array(array(
        'idempotency_key' => $key ?: 'test-message:' . api_uuid(),
        'aggregate_type' => $type, 'aggregate_id' => $aggregate ?: 'catalog-' . str_repeat('a', 48),
        'operation' => $operation, 'payload' => $payload
    )));
}
function verify_resident($db, $sync, array $identity)
{
    $controller = new Residents();
    $controller->db = $db;
    $controller->Sync_model = $sync;
    $controller->load = new class { public function model($name) {} };
    $controller->payload = $identity;
    return $controller->verify();
}
$socket = getenv('SMARTDESA_TEST_DB_SOCKET') ?: NULL;
$host = $socket ? 'localhost' : (getenv('SMARTDESA_TEST_DB_HOST') ?: '127.0.0.1');
$user = getenv('SMARTDESA_TEST_DB_USER') ?: 'root';
$password = getenv('SMARTDESA_TEST_DB_PASS') ?: '';
$port = (int) (getenv('SMARTDESA_TEST_DB_PORT') ?: 3306);
$admin = new mysqli($host, $user, $password, '', $port, $socket);
if ($admin->connect_errno) { fwrite(STDERR, "Test database unavailable.\n"); exit(1); }
$database = 'sdw_flow_test_' . bin2hex(random_bytes(8));
$created = FALSE;
$db = NULL;
$exitCode = 0;
try {
    if (!$admin->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $created = TRUE;
    $admin->select_db($database);
    sql_batch($admin, file_get_contents($root . '/database/schema.sql'));
    sql_batch($admin, 'DROP TABLE village_service_catalog, village_resident_directory, village_resident_snapshots, village_resident_snapshot_batches, resident_verification_attempts;
        ALTER TABLE service_requests DROP INDEX idx_requests_catalog, DROP COLUMN catalog_service_id, DROP COLUMN form_schema_version, DROP COLUMN document_size, DROP COLUMN document_sha256;
        ALTER TABLE request_documents DROP INDEX idx_request_documents_field, DROP COLUMN field_key;
        ALTER TABLE citizen_profiles DROP INDEX uniq_citizen_source, DROP COLUMN local_citizen_key, DROP COLUMN name_hash;
        ALTER TABLE sync_messages MODIFY aggregate_id CHAR(36) NOT NULL;');
    for ($pass = 0; $pass < 2; $pass++) {
        foreach (array('006_service_catalog', '007_resident_directory', '008_unique_citizen_source', '009_official_documents', '010_sync_aggregate_keys', '011_official_html') as $migration) {
            $apiSql = file_get_contents($root . '/database/migrations/' . $migration . '.sql');
            check($apiSql === file_get_contents($pwa . '/database/migrations/' . $migration . '.sql'), "API/PWA migration $migration matches (pass $pass)");
            sql_batch($admin, $apiSql);
        }
    }
    check($admin->query("SHOW COLUMNS FROM sync_messages LIKE 'aggregate_id'")->fetch_assoc()['Type'] === 'varchar(120)', 'old database accepts long aggregate keys after repeatable migration');
    $db = DB(array('hostname' => $socket ?: $host, 'username' => $user, 'password' => $password,
        'database' => $database, 'dbdriver' => 'mysqli', 'port' => $port, 'pconnect' => FALSE,
        'db_debug' => FALSE, 'char_set' => 'utf8mb4', 'dbcollat' => 'utf8mb4_unicode_ci'), TRUE);
    $GLOBALS['test_ci'] = (object) array('db' => $db);
    $installations = array();
    foreach (array('95.01.03.2009', '95.01.03.2003') as $i => $code) {
        $village = api_uuid();
        $db->insert('village_tenants', array('id' => $village, 'province_code' => '95', 'province_name' => 'Test',
            'regency_code' => '95.01', 'regency_name' => 'Test', 'district_code' => '95.01.03',
            'district_name' => 'Test', 'village_code' => $code, 'name' => 'Test Village ' . $i));
        $installations[] = $installation = array('id' => api_uuid(), 'village_id' => $village, 'installation_code' => 'TEST-' . $i);
        check($db->insert('village_installations', array_merge($installation, array(
            'sync_key_hash' => hash('sha256', 'test-key-' . $i),
            'sync_secret_hash' => hash('sha256', 'test-secret-' . $i)
        ))), 'isolated test installation created');
    }
    $installation = $installations[0];
    $other = $installations[1];
    $db->insert('roles', array('id' => 1, 'name' => 'Warga', 'slug' => 'warga'));
    $db->insert('service_types', array('id' => 30, 'slug' => 'unused-legacy', 'name' => 'Legacy', 'short_name' => 'Legacy'));
    $sync = new Sync_model();
    check($sync->ensure_resident_schema(), 'model detects database through CodeIgniter magic property');
    $schema = array('version' => 2, 'fields' => array(
        array('key' => 'usaha', 'label' => 'Nama Usaha', 'type' => 'text', 'required' => TRUE),
        array('key' => 'jenis', 'label' => 'Jenis', 'type' => 'select', 'required' => TRUE, 'options' => array(array('value' => 'kios', 'label' => 'Kios'))),
        array('key' => 'tanggal', 'label' => 'Tanggal', 'type' => 'date', 'required' => TRUE),
        array('key' => 'ktp', 'label' => 'KTP', 'type' => 'file', 'required' => TRUE)
    ));
    $service = array('service_key' => 'test-usaha', 'name' => 'Surat Usaha', 'short_name' => 'Usaha', 'template_key' => 'test-usaha', 'form_schema' => $schema);
    $catalog = array('services' => array($service));
    $admin->query('ALTER TABLE sync_messages MODIFY aggregate_id CHAR(36) NOT NULL');
    $result = push_message($sync, $installation, 'service_catalog', 'upsert', $catalog, NULL, 'catalog-test-first');
    check($result['accepted'] === 1, 'API accepts real long catalog key');
    check($admin->query("SHOW COLUMNS FROM sync_messages LIKE 'aggregate_id'")->fetch_assoc()['Type'] === 'varchar(120)', 'API safely upgrades legacy aggregate column before enqueue');
    check(push_message($sync, $installation, 'service_catalog', 'upsert', $catalog, NULL, 'catalog-test-first')['results'][0]['status'] === 'duplicate', 'same catalog delivery is idempotent');
    check(push_message($sync, $other, 'service_catalog', 'upsert', $catalog, NULL, 'catalog-test-first')['rejected'] === 1, 'idempotency key cannot be borrowed by another village');
    check(push_message($sync, $other, 'service_catalog', 'upsert', $catalog)['accepted'] === 1, 'another village owns an independent catalog');
    check(push_message($sync, $installation, 'unknown', 'unknown', array())['rejected'] === 1, 'unsupported change is not silently acknowledged');
    $badCatalog = $catalog;
    $badCatalog['services'][0]['form_schema'] = 'broken-schema';
    check(push_message($sync, $installation, 'service_catalog', 'upsert', $badCatalog)['rejected'] === 1, 'malformed form cannot silently remove required fields');

    $source = str_repeat('a', 24) . ':1';
    $snapshot = array('snapshot_id' => hash('sha256', 'snapshot-a'), 'snapshot_created_at' => date('c', time() - 10),
        'batch_index' => 1, 'batch_total' => 1, 'residents' => array(array('source_key' => $source,
            'nik' => '9501010101010001', 'kk' => '9501010101010002', 'name' => 'Test Citizen')));
    $identity = array('village_code' => '95.01.03.2009', 'nik' => '9501010101010001', 'kk' => '9501010101010002', 'name' => 'Test Citizen');
    $verification = verify_resident($db, $sync, $identity);
    check($verification['error'] === 'resident_directory_unavailable' && $verification['http_status'] === 503,
        'missing snapshot reports unsynced directory instead of identity mismatch');
    $partial = $snapshot;
    $partial['batch_total'] = 2;
    check(push_message($sync, $other, 'resident_directory', 'snapshot', $partial)['accepted'] === 1, 'first partial batch accepted');
    $otherIdentity = $identity;
    $otherIdentity['village_code'] = '95.01.03.2003';
    check(verify_resident($db, $sync, $otherIdentity)['error'] === 'resident_directory_unavailable',
        'partial first snapshot does not claim registration directory is ready');
    check(push_message($sync, $installation, 'resident_directory', 'snapshot', $snapshot, 'resident-' . str_repeat('a', 78))['accepted'] === 1, 'API accepts long resident snapshot key');
    check(!empty(verify_resident($db, $sync, $identity)['success']), 'matching identity is verified after complete snapshot');
    check(verify_resident($db, $sync, $otherIdentity)['error'] === 'resident_directory_unavailable',
        'completed snapshot does not make another village ready');
    $wrongIdentity = $identity;
    $wrongIdentity['name'] = 'Different Name';
    check(verify_resident($db, $sync, $wrongIdentity)['error'] === 'resident_not_found', 'incorrect name remains rejected');
    $wrongIdentity = $identity;
    $wrongIdentity['kk'] = '9501010101010003';
    check(verify_resident($db, $sync, $wrongIdentity)['error'] === 'resident_not_found', 'incorrect household number remains rejected');
    $directory = $db->where('village_id', $installation['village_id'])->get('village_resident_directory')->row_array();
    check($directory['birth_date'] === NULL, 'missing birth date is stored as NULL in strict MariaDB');
    check(strlen($directory['nik_hash']) === 64 && $directory['nik_hash'] !== $snapshot['residents'][0]['nik'], 'central resident directory stores hashed identity');
    $stored = $db->where('aggregate_type', 'resident_directory')->get('sync_messages')->row_array();
    check(strpos($stored['payload_json'], '9501010101010001') === FALSE, 'central sync history does not retain raw resident identifiers');
    $db->insert('users', array('id' => 1, 'role_id' => 1, 'village_id' => $installation['village_id'], 'name' => 'Test Citizen', 'username' => 'test-only', 'password_hash' => 'not-a-login'));
    $db->insert('citizen_profiles', array('id' => api_uuid(), 'user_id' => 1, 'village_id' => $installation['village_id'], 'local_citizen_key' => $source, 'verification_status' => 'verified'));
    $citizen = array('id' => 1, 'village_id' => $installation['village_id'], 'name' => 'Test Citizen', 'phone' => '', 'local_citizen_key' => $source);
    $auth = new Auth_model();
    $requests = new Request_model();
    $requests->Auth_model = $auth;
    $requests->load = new class { public function model($name) {} };
    $accepts = new ReflectionMethod($requests, 'file_accepts_mime');
    $accepts->setAccessible(TRUE);
    check($accepts->invoke($requests, 'application/pdf', '.pdf')
        && !$accepts->invoke($requests, 'image/png', '.pdf'), 'attachment extension restrictions validate actual MIME');
    $data = array('service_type' => 'test-usaha', 'purpose' => 'Keperluan pengujian', 'form_fields' => array('usaha' => 'Kios Test', 'jenis' => 'kios', 'tanggal' => '2026-09-04'));
    check(empty($requests->create($citizen, $data)['success']) && $db->count_all('service_requests') === 0, 'required attachment blocks incomplete request');
    $catalog['services'][0]['form_schema']['fields'][3]['required'] = FALSE;
    check(push_message($sync, $installation, 'service_catalog', 'upsert', $catalog)['accepted'] === 1, 'updated form is published');
    $invalid = $data;
    $invalid['form_fields']['jenis'] = 'not-an-option';
    check(empty($requests->create($citizen, $invalid)['success']), 'invalid dynamic option rejected');
    $invalid = $data;
    $invalid['form_fields']['tanggal'] = '2026-02-31';
    check(empty($requests->create($citizen, $invalid)['success']), 'invalid calendar date rejected');
    $invalid = $data;
    $invalid['form_fields']['unexpected'] = 'payload';
    check(empty($requests->create($citizen, $invalid)['success']), 'unknown dynamic input rejected');
    $foreign = $citizen;
    $foreign['village_id'] = $other['village_id'];
    check(empty($requests->create($foreign, $data)['success']), 'resident cannot submit through another village catalog');
    $createdRequest = $requests->create($citizen, $data);
    check(!empty($createdRequest['success']), 'verified resident creates request with published dynamic form');
    $requestId = $createdRequest['id'];
    $row = $db->where('id', $requestId)->get('service_requests')->row_array();
    check((int) $row['service_type_id'] > 30 && (int) $row['catalog_service_id'] < 30, 'legacy service ID and village catalog ID remain distinct');
    check($db->count_all('request_status_history') === 1, 'submission and initial history are stored together');
    check($requests->find_for_user($requestId, 2) === NULL, 'another citizen cannot read the request');
    $catalog['services'][0]['form_schema']['version'] = 3;
    $catalog['services'][0]['form_schema']['fields'][0]['label'] = 'Renamed field';
    push_message($sync, $installation, 'service_catalog', 'upsert', $catalog);
    $display = $requests->find_for_user($requestId, 1);
    check($display['form_schema']['version'] === 2 && $display['form_schema']['fields'][0]['label'] === 'Nama Usaha', 'historical request preserves its form schema snapshot');
    $messages = $sync->pull($installation);
    check(count($messages) === 1 && $messages[0]['payload']['citizen_local_key'] === $source
        && $messages[0]['payload']['form_data']['usaha'] === 'Kios Test', 'local pull receives verified identity and submitted values');
    check($sync->pull($other) === array(), 'other village cannot pull this request');
    check($sync->acknowledge($installation, array(array('id' => $messages[0]['id'], 'status' => 'processed'))) === 1, 'local acknowledgement commits delivery');
    foreach (array('verified', 'approved') as $status) {
        check(push_message($sync, $installation, 'service_request', 'status_update', array('status' => $status, 'actor_role' => 'pelayanan-surat'), $requestId)['accepted'] === 1, 'Pelayanan Surat advances status to ' . $status);
    }
    check(push_message($sync, $installation, 'service_request', 'status_update', array('status' => 'issued', 'actor_role' => 'administrator'), $requestId)['rejected'] === 1, 'issued status requires an official document endpoint');
    check(push_message($sync, $other, 'service_request', 'status_update', array('status' => 'rejected', 'actor_role' => 'administrator', 'note' => 'Test'), $requestId)['rejected'] === 1, 'another village cannot change request status');
    $empty = $snapshot;
    $empty['snapshot_id'] = hash('sha256', 'snapshot-empty');
    $empty['snapshot_created_at'] = date('c');
    $empty['residents'] = array();
    check(push_message($sync, $installation, 'resident_directory', 'snapshot', $empty)['accepted'] === 1, 'new empty snapshot accepted');
    check(verify_resident($db, $sync, $identity)['error'] === 'resident_not_found',
        'completed empty directory rejects removed resident without claiming sync is missing');
    check(!$auth->citizen_is_verified(1) && empty($requests->create($citizen, $data)['success']), 'removed resident cannot make new requests');
    push_message($sync, $installation, 'resident_directory', 'snapshot', $snapshot);
    check(!$auth->citizen_is_verified(1), 'late older snapshot cannot reactivate removed resident');
    push_message($sync, $installation, 'service_catalog', 'upsert', array('services' => array()));
    check($requests->service_types($installation['village_id']) === array()
        && count($requests->service_types($other['village_id'])) === 1, 'unpublishing catalog affects only owning village');
    echo "OK: $checks API/PWA workflow checks passed. HTTP upload/authentication are outside this test.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . ($db ? $db->last_query() . "\n" : '') . $e->getTraceAsString() . "\n");
    $exitCode = 1;
} finally {
    if ($db) $db->close();
    if ($created) $admin->query("DROP DATABASE `$database`");
    $admin->close();
}
exit($exitCode);
