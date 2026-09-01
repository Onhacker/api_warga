<?php
/**
 * Report installation coverage and sync health without revealing secrets.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function load_env_file($path, $override = false)
{
    if (!is_readable($path)) return false;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) $value = substr($value, 1, -1);
        if ($key !== '' && ($override || getenv($key) === false)) putenv($key . '=' . $value);
    }
    return true;
}

function mask_code($code)
{
    $code = (string) $code;
    return strlen($code) > 8 ? substr($code, 0, 5) . '...' . substr($code, -3) : $code;
}

function usage()
{
    return "Gunakan: php tools/report_installations.php [--format=json|csv|text] [--env=/path/.env]\n";
}

$options = getopt('', array('format::', 'env:', 'help'));
if (isset($options['help'])) {
    fwrite(STDOUT, usage());
    exit(0);
}
$format = strtolower(trim((string) (isset($options['format']) ? $options['format'] : 'text')));
if (!in_array($format, array('json', 'csv', 'text'), true)) {
    fwrite(STDERR, usage());
    exit(2);
}

$envPath = isset($options['env']) ? trim((string) (is_array($options['env']) ? end($options['env']) : $options['env'])) : '';
if ($envPath === '') $envPath = __DIR__ . '/../.env';
if (!load_env_file($envPath, isset($options['env']))) {
    fwrite(STDERR, 'File .env tidak ditemukan atau tidak dapat dibaca: ' . $envPath . PHP_EOL);
    exit(1);
}
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
    . ';port=' . (getenv('DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('DB_NAME') ?: 'smartdesa_warga')
    . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ));
    $villages = $pdo->query("SELECT id, village_code, name AS village_name, district_name FROM village_tenants WHERE status = 'active' ORDER BY district_name ASC, name ASC")->fetchAll();
    $hasEnrollmentSchema = (bool) $pdo->query("SHOW COLUMNS FROM village_installations LIKE 'enrollment_code_hash'")->fetch();
    $enrollmentColumns = $hasEnrollmentSchema
        ? ', enrollment_code_hash, enrollment_expires_at, enrollment_used_at'
        : '';
    $installations = $pdo->query("SELECT id, village_id, installation_code, status, app_version, last_seen_at, last_sync_at{$enrollmentColumns} FROM village_installations ORDER BY village_id ASC, (status = 'active') DESC, updated_at DESC, id DESC")->fetchAll();
    $queueRows = $pdo->query("SELECT village_id,
                                     SUM(direction = 'cloud_to_local' AND status IN ('pending', 'processing')) AS pending_inbound,
                                     SUM(direction = 'local_to_cloud' AND status IN ('pending', 'processing')) AS pending_outbound,
                                     SUM(status = 'failed') AS failed_messages
                              FROM sync_messages GROUP BY village_id")->fetchAll();
    $installationsByVillage = array();
    foreach ($installations as $installation) {
        $villageId = (string) $installation['village_id'];
        if (!isset($installationsByVillage[$villageId])) $installationsByVillage[$villageId] = array();
        $installationsByVillage[$villageId][] = $installation;
    }
    $queuesByVillage = array();
    foreach ($queueRows as $queue) $queuesByVillage[(string) $queue['village_id']] = $queue;

    $result = array();
    foreach ($villages as $village) {
        $villageId = (string) $village['id'];
        $villageInstallations = isset($installationsByVillage[$villageId]) ? $installationsByVillage[$villageId] : array();
        $activeInstallations = array_values(array_filter($villageInstallations, function ($row) {
            return strtolower(trim((string) $row['status'])) === 'active';
        }));
        if (count($activeInstallations) > 1) {
            $installationStatus = 'multiple_active';
            $installation = $activeInstallations[0];
        } elseif (count($activeInstallations) === 1) {
            $installationStatus = 'active';
            $installation = $activeInstallations[0];
        } elseif (count($villageInstallations) > 0) {
            $installationStatus = 'inactive';
            $installation = $villageInstallations[0];
        } else {
            $installationStatus = 'not_provisioned';
            $installation = array();
        }
        if (!$hasEnrollmentSchema) {
            $enrollmentStatus = 'migration_required';
        } elseif ($installationStatus === 'not_provisioned') {
            $enrollmentStatus = 'not_provisioned';
        } elseif ($installationStatus === 'multiple_active') {
            $enrollmentStatus = 'multiple_active';
        } elseif ($installationStatus === 'inactive') {
            $enrollmentStatus = 'inactive';
        } elseif (!empty($installation['enrollment_used_at'])) {
            $enrollmentStatus = 'used';
        } elseif (empty($installation['enrollment_code_hash'])) {
            $enrollmentStatus = 'missing';
        } elseif (!empty($installation['enrollment_expires_at']) && strtotime((string) $installation['enrollment_expires_at']) < time()) {
            $enrollmentStatus = 'expired';
        } else {
            $enrollmentStatus = 'ready';
        }
        $queue = isset($queuesByVillage[$villageId]) ? $queuesByVillage[$villageId] : array();
        $result[] = array(
            'village_code' => (string) $village['village_code'],
            'village_name' => (string) $village['village_name'],
            'district_name' => (string) $village['district_name'],
            'installation_status' => $installationStatus,
            'installation_count' => count($villageInstallations),
            'installation_code' => !empty($installation['installation_code']) ? mask_code($installation['installation_code']) : '',
            'enrollment_status' => $enrollmentStatus,
            'enrollment_expires_at' => (string) (!empty($installation['enrollment_expires_at']) ? $installation['enrollment_expires_at'] : ''),
            'app_version' => (string) (!empty($installation['app_version']) ? $installation['app_version'] : ''),
            'last_seen_at' => (string) (!empty($installation['last_seen_at']) ? $installation['last_seen_at'] : ''),
            'last_sync_at' => (string) (!empty($installation['last_sync_at']) ? $installation['last_sync_at'] : ''),
            'pending_inbound' => (int) (!empty($queue['pending_inbound']) ? $queue['pending_inbound'] : 0),
            'pending_outbound' => (int) (!empty($queue['pending_outbound']) ? $queue['pending_outbound'] : 0),
            'failed_messages' => (int) (!empty($queue['failed_messages']) ? $queue['failed_messages'] : 0)
        );
    }

    if ($format === 'json') {
        echo json_encode(array('success' => true, 'count' => count($result), 'villages' => $result), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if ($format === 'csv') {
        $output = fopen('php://output', 'w');
        if (!$output) throw new RuntimeException('Output tidak dapat dibuka.');
        fputcsv($output, array_keys(isset($result[0]) ? $result[0] : array('village_code' => '')));
        foreach ($result as $row) fputcsv($output, $row);
        fclose($output);
        exit(0);
    }

    printf("%-16s %-30s %-22s %-17s %-4s %-18s %-18s %-19s %-12s %-19s %-19s %5s %5s %5s\n", 'KODE DESA', 'NAMA DESA', 'DISTRIK', 'STATUS', 'JML', 'INSTALASI', 'AKTIVASI', 'KADALUWARSA', 'VERSI', 'TERAKHIR DILIHAT', 'TERAKHIR SINKRON', 'IN', 'OUT', 'FAIL');
    printf("%s\n", str_repeat('-', 214));
    foreach ($result as $row) {
        printf("%-16s %-30s %-22s %-17s %4d %-18s %-18s %-19s %-12s %-19s %-19s %5d %5d %5d\n",
            substr($row['village_code'], 0, 16),
            substr($row['village_name'], 0, 30),
            substr($row['district_name'], 0, 22),
            substr($row['installation_status'], 0, 17),
            $row['installation_count'],
            substr($row['installation_code'], 0, 18),
            substr($row['enrollment_status'], 0, 18),
            substr($row['enrollment_expires_at'], 0, 19),
            substr($row['app_version'], 0, 12),
            substr($row['last_seen_at'], 0, 19),
            substr($row['last_sync_at'], 0, 19),
            $row['pending_inbound'], $row['pending_outbound'], $row['failed_messages']
        );
    }
    $installed = count(array_filter($result, function ($row) { return $row['installation_status'] === 'active'; }));
    printf("\nTotal desa aktif: %d | Sudah terpasang: %d | Belum terpasang: %d\n", count($result), $installed, count($result) - $installed);
} catch (Throwable $error) {
    fwrite(STDERR, 'Gagal membuat laporan: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
