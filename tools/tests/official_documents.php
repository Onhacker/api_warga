<?php
// Creates and drops only a uniquely named test database. Never loads production .env.
if (PHP_SAPI !== 'cli') exit(1);
define('BASEPATH', dirname(__DIR__, 2) . '/system/');
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('ENVIRONMENT', 'testing');
error_reporting(E_ALL & ~E_DEPRECATED);
mysqli_report(MYSQLI_REPORT_OFF);

function log_message($level, $message) {}
function is_php($version) { return version_compare(PHP_VERSION, $version, '>='); }
function show_error($message) { throw new RuntimeException(is_array($message) ? implode(' ', $message) : $message); }
class CI_Model { public $db; }
class MY_Controller
{
    public $rawBody = '';
    public $installation;
    public $Sync_model;
    public $load;
    protected function require_method($method) { return TRUE; }
    protected function authenticate_installation() { return $this->installation; }
    protected function touch_installation($synced) {}
    protected function fail($message, $status, $code) { return array('success' => FALSE, 'status_code' => $status, 'error_code' => $code, 'message' => $message); }
    protected function respond($data) { return $data; }
}
require APPPATH . 'helpers/api_helper.php';
require BASEPATH . 'database/DB.php';
require APPPATH . 'models/Sync_model.php';
require APPPATH . 'controllers/v1/Requests.php';

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
function remove_test_tree($path)
{
    if (!is_dir($path) || is_link($path)) return;
    foreach (new FilesystemIterator($path) as $file) {
        if ($file->isDir() && !$file->isLink()) remove_test_tree($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($path);
}

$socket = getenv('SMARTDESA_TEST_DB_SOCKET') ?: NULL;
$host = $socket ? 'localhost' : (getenv('SMARTDESA_TEST_DB_HOST') ?: '127.0.0.1');
$user = getenv('SMARTDESA_TEST_DB_USER') ?: 'root';
$password = getenv('SMARTDESA_TEST_DB_PASS') ?: '';
$port = (int) (getenv('SMARTDESA_TEST_DB_PORT') ?: 3306);
$admin = new mysqli($host, $user, $password, '', $port, $socket);
if ($admin->connect_errno) { fwrite(STDERR, "Test database unavailable: " . $admin->connect_errno . "\n"); exit(1); }
$database = 'sdw_test_' . bin2hex(random_bytes(8));
$storage = sys_get_temp_dir() . '/' . $database;
$created = FALSE;
$db = NULL;
$exitCode = 0;
try {
    if (!$admin->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException('Cannot create test database.');
    $created = TRUE;
    $admin->select_db($database);
    sql_batch($admin, file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
    $admin->query('ALTER TABLE service_requests DROP COLUMN document_size, DROP COLUMN document_sha256');
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/009_official_documents.sql');
    sql_batch($admin, $migration);
    sql_batch($admin, $migration);
    check($admin->query("SHOW COLUMNS FROM service_requests LIKE 'document_sha256'")->num_rows === 1, 'migration 009 is repeatable');

    $db = DB(array('hostname' => $socket ?: $host, 'username' => $user, 'password' => $password,
        'database' => $database, 'dbdriver' => 'mysqli', 'port' => $port, 'pconnect' => FALSE,
        'db_debug' => FALSE, 'char_set' => 'utf8mb4', 'dbcollat' => 'utf8mb4_unicode_ci',
        'save_queries' => TRUE), TRUE);
    $village = api_uuid();
    $otherVillage = api_uuid();
    foreach (array($village, $otherVillage) as $i => $id) {
        $db->insert('village_tenants', array('id' => $id, 'province_code' => '95', 'province_name' => 'Test',
            'regency_code' => '95.01', 'regency_name' => 'Test', 'district_code' => '95.01.01',
            'district_name' => 'Test', 'village_code' => 'test-' . $i, 'name' => 'Test ' . $i));
    }
    $db->insert('roles', array('id' => 1, 'name' => 'Warga', 'slug' => 'warga'));
    $db->insert('users', array('id' => 1, 'role_id' => 1, 'village_id' => $village, 'name' => 'Test Citizen', 'username' => 'test-citizen', 'password_hash' => 'not-a-login'));
    $db->insert('service_types', array('id' => 1, 'slug' => 'test-letter', 'name' => 'Test Letter', 'short_name' => 'Test'));
    $requests = array();
    foreach (array('approved', 'submitted', 'approved', 'approved') as $i => $status) {
        $requests[] = $id = api_uuid();
        $db->insert('service_requests', array('id' => $id, 'request_code' => 'TEST-' . $i,
            'citizen_user_id' => 1, 'village_id' => $i === 2 ? $otherVillage : $village,
            'service_type_id' => 1, 'status' => $status, 'payload_json' => '{}'));
    }
    check($db->count_all('service_requests') === 4, 'fixtures stored in isolated database');
    $model = new Sync_model();
    $model->db = $db;
    mkdir($storage, 0700);
    putenv('PRIVATE_STORAGE_PATH=' . $storage);
    putenv('API_DEMO_MODE=0');
    $controller = new Requests();
    $controller->installation = array('id' => api_uuid(), 'village_id' => $village);
    $controller->Sync_model = $model;
    $controller->load = new class { public function model($name) {} };
    $body = "%PDF-1.4\n% SmartDesa transport test\n%%EOF\n";
    $controller->rawBody = $body;
    $_SERVER['HTTP_X_SMARTDESA_DOCUMENT_SHA256'] = hash('sha256', $body);
    $_SERVER['HTTP_X_SMARTDESA_DOCUMENT_REFERENCE'] = rtrim(strtr(base64_encode('TEST/001/2026'), '+/', '-_'), '=');
    $_SERVER['HTTP_X_SMARTDESA_DOCUMENT_NAME'] = rtrim(strtr(base64_encode('surat-test.pdf'), '+/', '-_'), '=');
    $_SERVER['HTTP_X_SMARTDESA_ACTOR_NAME'] = rtrim(strtr(base64_encode('Test Operator'), '+/', '-_'), '=');
    $result = $controller->official_document($requests[0]);
    check(!empty($result['success']) && $result['service_request_id'] === $requests[0], 'approved request publishes official document');
    $row = $db->where('id', $requests[0])->get('service_requests')->row_array();
    check($row['status'] === 'issued' && $row['document_sha256'] === hash('sha256', $body)
        && file_get_contents($row['document_path']) === $body, 'issued status and exact PDF saved together');
    $again = $controller->official_document($requests[0]);
    check(!empty($again['success']) && !empty($again['duplicate']), 'same PDF retry is idempotent');
    check($db->count_all('notifications') === 1 && $db->count_all('request_status_history') === 1, 'retry does not duplicate notification or history');
    check(empty($controller->official_document($requests[1])['success']), 'unapproved request cannot publish');
    check(empty($controller->official_document($requests[2])['success']), 'another village cannot publish');
    check(empty($controller->official_document(api_uuid())['success']), 'unknown request cannot publish');
    $controller->rawBody = $body . '% changed';
    check($controller->official_document($requests[0])['error_code'] === 'invalid_document_hash', 'wrong PDF hash rejected');
    $_SERVER['HTTP_X_SMARTDESA_DOCUMENT_SHA256'] = hash('sha256', $controller->rawBody);
    check(empty($controller->official_document($requests[0])['success']), 'issued request cannot replace official PDF');
    check(file_get_contents($row['document_path']) === $body, 'rejected replacement preserves original PDF');
    $controller->rawBody = '<html>not a PDF</html>';
    check($controller->official_document($requests[3])['error_code'] === 'invalid_document', 'non-PDF rejected');
    $controller->rawBody = $body;
    $_SERVER['HTTP_X_SMARTDESA_DOCUMENT_SHA256'] = hash('sha256', $body);
    $admin->query("CREATE TRIGGER test_fail_notice BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
    check(empty($controller->official_document($requests[3])['success']), 'database failure rejects publication');
    $rolledBack = $db->where('id', $requests[3])->get('service_requests')->row_array();
    check($rolledBack['status'] === 'approved' && $rolledBack['document_path'] === NULL, 'database failure rolls back request and document metadata');
    check(count(glob($storage . '/official-documents/' . $village . '/' . $requests[3] . '/*.pdf')) === 0, 'database failure removes unpublished PDF');
    echo "OK: $checks checks passed. Authentication middleware is outside this isolated controller test.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db) $db->close();
    if ($created) $admin->query("DROP DATABASE `$database`");
    $admin->close();
    remove_test_tree($storage);
}
exit($exitCode);
