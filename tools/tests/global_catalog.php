<?php
// Integration checks for the centrally managed service catalogue.
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

class CI_Model { public $db; }

require BASEPATH . 'database/DB.php';
require APPPATH . 'helpers/api_helper.php';
require APPPATH . 'models/Global_catalog_model.php';
require APPPATH . 'models/Monitoring_model.php';
require APPPATH . 'models/Sync_model.php';
require $pwa . '/application/helpers/warga_helper.php';
require $pwa . '/application/models/Request_model.php';

set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return FALSE;
});
putenv('API_DEMO_MODE=0');
putenv('WARGA_DEMO_MODE=0');
putenv('APP_KEY=' . str_repeat('global-catalog-test-', 3));

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

function service_payload($key, $name, $minimum)
{
    return array(
        'service_key' => $key,
        'name' => $name,
        'short_name' => $name,
        'icon' => 'fa-file-alt',
        'description' => $name . ' untuk pengujian.',
        'requirements' => array('KTP'),
        'form_schema' => array('version' => 1, 'fields' => array(array(
            'key' => 'keperluan_' . str_replace('-', '_', $key),
            'label' => 'Keperluan',
            'type' => 'text',
            'required' => TRUE,
            'max_length' => 180
        ))),
        'template_key' => $key,
        'schema_version' => 1,
        'minimum_app_version' => $minimum,
        'submission_enabled' => TRUE,
        'availability_note' => '',
        'source_updated_at' => '2026-09-18 00:00:00',
        'source_hash' => hash('sha256', $key)
    );
}

$socket = getenv('SMARTDESA_TEST_DB_SOCKET') ?: NULL;
$host = $socket ? 'localhost' : (getenv('SMARTDESA_TEST_DB_HOST') ?: '127.0.0.1');
$user = getenv('SMARTDESA_TEST_DB_USER') ?: 'root';
$password = getenv('SMARTDESA_TEST_DB_PASS') ?: '';
$port = (int) (getenv('SMARTDESA_TEST_DB_PORT') ?: 3306);
$admin = new mysqli($host, $user, $password, '', $port, $socket);
if ($admin->connect_errno) { fwrite(STDERR, "Test database unavailable.\n"); exit(1); }

$database = 'sdw_global_catalog_' . bin2hex(random_bytes(8));
$created = FALSE;
$db = NULL;
$exitCode = 0;
try {
    if (!$admin->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException($admin->error);
    }
    $created = TRUE;
    $admin->select_db($database);
    sql_batch($admin, file_get_contents($root . '/database/schema.sql'));

    $apiMigration = file_get_contents($root . '/database/migrations/022_global_service_catalog.sql');
    $pwaMigration = file_get_contents($pwa . '/database/migrations/022_global_service_catalog.sql');
    check($apiMigration === $pwaMigration, 'API and PWA use the exact same global catalog migration');

    $villageA = api_uuid();
    $villageB = api_uuid();
    foreach (array(array($villageA, '95.01.01.2001', 'Kampung A'), array($villageB, '95.01.01.2002', 'Kampung B')) as $village) {
        $admin->query("INSERT INTO village_tenants
            (id, province_code, province_name, regency_code, regency_name, district_code, district_name, village_code, name)
            VALUES ('{$village[0]}', '95', 'Papua', '95.01', 'Jayawijaya', '95.01.01', 'Distrik Uji', '{$village[1]}', '{$village[2]}')");
    }
    $installationA = api_uuid();
    $installationB = api_uuid();
    $admin->query("INSERT INTO village_installations
        (id, village_id, installation_code, sync_key_hash, sync_secret_hash, app_version, status)
        VALUES
        ('$installationA', '$villageA', 'SDW-GLOBAL-A', '" . str_repeat('a', 64) . "', '" . str_repeat('b', 64) . "', '1.0.26', 'active'),
        ('$installationB', '$villageB', 'SDW-GLOBAL-B', '" . str_repeat('c', 64) . "', '" . str_repeat('d', 64) . "', '1.0.30', 'active')");

    $legacyA = json_encode(array('version' => 1, 'fields' => array()), JSON_UNESCAPED_SLASHES);
    $legacyB = json_encode(array('version' => 1, 'fields' => array()), JSON_UNESCAPED_SLASHES);
    $stmt = $admin->prepare('INSERT INTO village_service_catalog
        (village_id, service_key, name, short_name, requirements_json, form_schema_json, template_key,
         schema_version, sort_order, is_active, submission_enabled, source_revision, published_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1, ?, NOW())');
    $revision = 1;
    $key = 'legacy-a'; $name = 'Legacy A'; $requirements = '[]';
    $stmt->bind_param('sssssssi', $villageA, $key, $name, $name, $requirements, $legacyA, $key, $revision);
    $stmt->execute();
    $revision = 2;
    $key = 'legacy-b'; $name = 'Legacy B';
    $stmt->bind_param('sssssssi', $villageB, $key, $name, $name, $requirements, $legacyB, $key, $revision);
    $stmt->execute();
    $stmt->close();

    sql_batch($admin, $apiMigration);
    sql_batch($admin, $apiMigration);
    $state = $admin->query('SELECT * FROM global_service_catalog_state WHERE id=1')->fetch_assoc();
    check($state && (int) $state['is_ready'] === 0, 'migration remains in fallback mode until the first central publication');

    $db = DB(array(
        'hostname' => $socket ?: $host, 'username' => $user, 'password' => $password,
        'database' => $database, 'dbdriver' => 'mysqli', 'port' => $port, 'pconnect' => FALSE,
        'db_debug' => FALSE, 'char_set' => 'utf8mb4', 'dbcollat' => 'utf8mb4_unicode_ci'
    ), TRUE);
    $GLOBALS['test_ci'] = (object) array('db' => $db);

    $request = new Request_model();
    $request->db = $db;
    check(array_column($request->service_types($villageA), 'slug') === array('legacy-a'),
        'village A keeps its own legacy catalog before central publication');
    check(array_column($request->service_types($villageB), 'slug') === array('legacy-b'),
        'village B keeps its own legacy catalog before central publication');

    $catalog = new Global_catalog_model();
    $catalog->db = $db;
    $services = array(
        service_payload('global-basic', 'Layanan Dasar', '0.0.0'),
        service_payload('global-modern', 'Layanan Modern', '1.0.30')
    );
    $services[0]['submission_enabled'] = FALSE;
    $services[0]['availability_note'] = 'Layanan informasi untuk pengujian.';
    $bad = $catalog->publish(array(
        'services' => $services,
        'catalog_empty' => FALSE,
        'catalog_hash' => str_repeat('0', 64),
        'published_by' => 'Test Super Admin'
    ));
    check(empty($bad['success']) && $catalog->status()['ready'] === FALSE,
        'invalid central snapshot cannot switch PWA away from the legacy fallback');

    $emptyHash = hash('sha256', json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $empty = $catalog->publish(array(
        'services' => array(),
        'catalog_empty' => TRUE,
        'catalog_hash' => $emptyHash,
        'published_by' => 'Test Super Admin'
    ));
    check(empty($empty['success']) && $catalog->status()['ready'] === FALSE,
        'an empty central snapshot is rejected before it can hide every service');

    $hash = hash('sha256', json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $beforeRevision = (int) $catalog->status()['revision'];
    $published = $catalog->publish(array(
        'services' => $services,
        'catalog_empty' => FALSE,
        'catalog_hash' => $hash,
        'published_by' => 'Test Super Admin'
    ));
    check(!empty($published['success']) && (int) $published['revision'] === $beforeRevision + 1
        && (int) $published['service_count'] === 2, 'valid snapshot atomically activates the global catalog');

    $requestA = new Request_model();
    $requestA->db = $db;
    $requestB = new Request_model();
    $requestB->db = $db;
    check(array_column($requestA->service_types($villageA), 'slug') === array('global-basic'),
        'minimum version hides incompatible services only for an older village installation');
    check(array_column($requestB->service_types($villageB), 'slug') === array('global-basic', 'global-modern'),
        'compatible village reads the same central catalog without publishing its own copy');

    $monitor = new Monitoring_model();
    $monitor->db = $db;
    $catalogMetrics = new ReflectionMethod($monitor, 'catalog_metrics');
    $catalogMetrics->setAccessible(TRUE);
    $metrics = $catalogMetrics->invoke($monitor, array($villageA, $villageB), array(
        $villageA => array('app_version' => '1.0.26'),
        $villageB => array('app_version' => '1.0.30')
    ));
    check((int) $metrics[$villageA] === 1 && (int) $metrics[$villageB] === 2,
        'monitoring counts every visible service including information-only entries');

    $basicId = (int) $db->where('slug', 'global-basic')->get('service_types')->row_array()['id'];
    $db->insert('village_service_overrides', array(
        'village_id' => $villageB,
        'service_type_id' => $basicId,
        'is_visible' => 0
    ));
    $requestOverride = new Request_model();
    $requestOverride->db = $db;
    check(array_column($requestOverride->service_types($villageB), 'slug') === array('global-modern'),
        'optional village override does not duplicate or mutate the global definition');

    $repeat = $catalog->publish(array(
        'services' => $services,
        'catalog_empty' => FALSE,
        'catalog_hash' => $hash,
        'published_by' => 'Test Super Admin'
    ));
    check(!empty($repeat['already_published']) && (int) $repeat['revision'] === (int) $published['revision'],
        'identical central publication is idempotent');

    $legacyCount = (int) $db->where('village_id', $villageA)->count_all_results('village_service_catalog');
    $legacyServices = array(service_payload('legacy-overwrite', 'Legacy Overwrite', '0.0.0'));
    $legacyJson = json_encode($legacyServices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $legacyPayload = array(
        'catalog_version' => 99,
        'catalog_hash' => hash('sha256', $legacyJson),
        'catalog_empty' => FALSE,
        'services' => $legacyServices
    );
    $sync = new Sync_model();
    $sync->db = $db;
    $result = $sync->enqueue(array('id' => $installationA, 'village_id' => $villageA), array(array(
        'idempotency_key' => 'legacy-global-block-001',
        'aggregate_type' => 'service_catalog',
        'aggregate_id' => 'catalog-global-block-test',
        'operation' => 'upsert',
        'event_version' => 99,
        'payload' => $legacyPayload
    )));
    $audit = $db->where('idempotency_key', 'legacy-global-block-001')->get('sync_messages')->row_array();
    check((int) $result['accepted'] === 1
        && (int) $db->where('village_id', $villageA)->count_all_results('village_service_catalog') === $legacyCount
        && strpos((string) $audit['payload_json'], 'ignored_by_global_catalog') !== FALSE,
        'an old village client is acknowledged but cannot overwrite the global catalog');

    echo "OK: $checks global catalog checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . ($db ? $db->last_query() . "\n" : '') . $e->getTraceAsString() . "\n");
    $exitCode = 1;
} finally {
    if ($db) $db->close();
    if ($created) $admin->query("DROP DATABASE `$database`");
    $admin->close();
}
exit($exitCode);
