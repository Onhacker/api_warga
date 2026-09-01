<?php
/**
 * Issue one-time activation codes for existing SmartDesa installations.
 * Codes are written once to a 0600 file outside the public document root.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function load_env_file($path, $override = false)
{
    if (!is_readable($path)) return false;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key); $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) $value = substr($value, 1, -1);
        if ($key !== '' && ($override || getenv($key) === false)) putenv($key . '=' . $value);
    }
    return true;
}

function option_values($options, $key)
{
    if (!isset($options[$key])) return array();
    $values = is_array($options[$key]) ? $options[$key] : array($options[$key]);
    $out = array();
    foreach ($values as $value) {
        $value = strtoupper(trim((string) $value));
        if ($value !== '') $out[] = $value;
    }
    return array_values(array_unique($out));
}

function option_value($options, $key)
{
    if (!isset($options[$key])) return '';
    $value = is_array($options[$key]) ? end($options[$key]) : $options[$key];
    return trim((string) $value);
}

function activation_code()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $raw = '';
    for ($i = 0; $i < 16; $i++) $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return 'SDW-' . implode('-', str_split($raw, 4));
}

function normalized_code($code)
{
    return preg_replace('/[^A-Z2-9]/', '', strtoupper((string) $code));
}

function path_is_inside($path, $root)
{
    $path = realpath($path); $root = realpath($root);
    if ($path === false || $root === false) return false;
    return $path === $root || strpos($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}

function write_private_json($path, array $payload, $publicRoot)
{
    if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) throw new RuntimeException('--output wajib berupa path absolut.');
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Folder output tidak dapat ditulis: ' . $dir);
    if (path_is_inside($dir, $publicRoot)) throw new RuntimeException('File kode aktivasi tidak boleh disimpan di public_html.');
    if (file_exists($path)) throw new RuntimeException('File output sudah ada. Gunakan nama file baru.');
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Kode aktivasi belum dapat diformat.');
    $tmp = tempnam($dir, '.smartdesa-enrollment-');
    if ($tmp === false) throw new RuntimeException('File sementara tidak dapat dibuat.');
    @chmod($tmp, 0600);
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) { @unlink($tmp); throw new RuntimeException('File sementara tidak dapat ditulis.'); }
    return array('temporary' => $tmp, 'path' => $path);
}

function usage()
{
    return "Gunakan:\n"
        . "  php tools/issue_enrollment_codes.php --all [--write --output=/path/codes.json]\n"
        . "  php tools/issue_enrollment_codes.php --village=KODE [--write --output=/path/codes.json]\n\n"
        . "Opsi: --env=/path/.env --days=90 --force --write --output=/path/file.json\n"
        . "Tanpa --write hanya menampilkan status dan tidak mengubah database.\n"
        . "Tanpa --force, hanya kode hilang atau kedaluwarsa yang diterbitkan.\n"
        . "Gunakan --force hanya untuk membatalkan dan mengganti kode yang masih berlaku.\n";
}

$options = getopt('', array('all', 'village:', 'write', 'output:', 'env:', 'days:', 'force', 'help'));
if (isset($options['help'])) { fwrite(STDOUT, usage()); exit(0); }
$all = isset($options['all']);
$villages = option_values($options, 'village');
if (($all && count($villages) > 0) || (!$all && count($villages) === 0)) { fwrite(STDERR, usage()); exit(2); }
$write = isset($options['write']);
$force = isset($options['force']);
$days = max(1, min(365, (int) (option_value($options, 'days') ?: 90)));
$output = option_value($options, 'output');
$envPath = option_value($options, 'env');
if ($envPath === '') $envPath = __DIR__ . '/../.env';
if (!load_env_file($envPath, isset($options['env']))) { fwrite(STDERR, 'File .env tidak dapat dibaca: ' . $envPath . PHP_EOL); exit(1); }

$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'smartdesa_warga') . ';charset=utf8mb4';
$preparedFile = null;
$committed = false;
try {
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
    if (!$pdo->query("SHOW COLUMNS FROM village_installations LIKE 'enrollment_code_hash'")->fetch()) throw new RuntimeException('Migration 004_installation_enrollment.sql belum dijalankan.');

    $where = "v.status = 'active' AND i.status = 'active'";
    $params = array();
    if (!$all) {
        $where .= ' AND UPPER(v.village_code) IN (' . implode(',', array_fill(0, count($villages), '?')) . ')';
        $params = $villages;
    }
    $query = $pdo->prepare("SELECT i.id, i.village_id, i.installation_code, i.sync_secret_encrypted, i.enrollment_code_hash, i.enrollment_expires_at, i.enrollment_used_at, v.village_code, v.name AS village_name, v.district_name, v.regency_name FROM village_installations i INNER JOIN village_tenants v ON v.id = i.village_id WHERE $where ORDER BY v.district_name, v.name, i.updated_at DESC");
    $query->execute($params);
    $rows = $query->fetchAll();

    $byVillage = array();
    foreach ($rows as $row) {
        $key = (string) $row['village_id'];
        if (!isset($byVillage[$key])) $byVillage[$key] = array();
        $byVillage[$key][] = $row;
    }
    if (!$all) {
        $found = array();
        foreach ($rows as $row) $found[] = strtoupper((string) $row['village_code']);
        $missing = array_values(array_diff($villages, array_unique($found)));
        if ($missing) throw new RuntimeException('Desa atau instalasi aktif tidak ditemukan: ' . implode(', ', $missing));
    }

    $now = time();
    $summary = array('selected' => count($byVillage), 'ready' => 0, 'used' => 0, 'expired' => 0, 'missing' => 0, 'missing_secret' => 0, 'multiple_active' => 0, 'issued' => 0);
    $report = array();
    $candidates = array();
    foreach ($byVillage as $villageRows) {
        if (count($villageRows) !== 1) {
            $summary['multiple_active']++;
            $report[] = array('village_code' => $villageRows[0]['village_code'], 'village_name' => $villageRows[0]['village_name'], 'district_name' => $villageRows[0]['district_name'], 'status' => 'multiple_active');
            continue;
        }
        $row = $villageRows[0];
        $hasCode = !empty($row['enrollment_code_hash']);
        $expired = $hasCode && !empty($row['enrollment_expires_at']) && strtotime((string) $row['enrollment_expires_at']) < $now;
        $used = !empty($row['enrollment_used_at']);
        $missingSecret = empty($row['sync_secret_encrypted']);
        $status = $missingSecret ? 'missing_secret' : ($used ? 'used' : ($expired ? 'expired' : ($hasCode ? 'ready' : 'missing')));
        if (isset($summary[$status])) $summary[$status]++;
        $report[] = array('village_code' => $row['village_code'], 'village_name' => $row['village_name'], 'district_name' => $row['district_name'], 'status' => $status, 'expires_at' => (string) ($row['enrollment_expires_at'] ?: ''));
        if (!$missingSecret && ($force || !$hasCode || $expired)) $candidates[] = $row;
    }

    if (!$write) {
        echo json_encode(array('success' => true, 'mode' => 'preview', 'summary' => $summary, 'villages' => $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if ($candidates && $output === '') throw new RuntimeException('--output wajib diisi ketika kode baru dibuat.');

    $expiresAt = date('Y-m-d H:i:s', $now + ($days * 86400));
    $issued = array();
    $updates = array();
    foreach ($candidates as $row) {
        $code = activation_code();
        $updates[] = array('id' => $row['id'], 'hash' => hash('sha256', normalized_code($code)));
        $issued[] = array('village_code' => $row['village_code'], 'village_name' => $row['village_name'], 'district_name' => $row['district_name'], 'regency_name' => $row['regency_name'], 'enrollment_code' => $code, 'expires_at' => $expiresAt);
    }
    $publicRoot = realpath(dirname($envPath));
    if ($publicRoot === false) $publicRoot = realpath(__DIR__ . '/..');
    if ($issued) $preparedFile = write_private_json($output, array('format' => 'smartdesa-warga-enrollment-v1', 'created_at' => date('c'), 'warning' => 'Berikan hanya satu kode kepada desa yang sesuai. Jangan unggah file ini ke public_html.', 'codes' => $issued), $publicRoot);

    if (!$pdo->beginTransaction()) throw new RuntimeException('Transaksi penerbitan kode tidak dapat dimulai.');
    $update = $pdo->prepare('UPDATE village_installations SET enrollment_code_hash = ?, enrollment_expires_at = ?, enrollment_used_at = NULL, enrollment_device_hash = NULL, updated_at = NOW() WHERE id = ? AND status = \'active\'');
    foreach ($updates as $item) $update->execute(array($item['hash'], $expiresAt, $item['id']));
    if (!$pdo->commit()) throw new RuntimeException('Transaksi penerbitan kode tidak dapat disimpan.');
    $committed = true;

    $file = null;
    if ($preparedFile) {
        if (file_exists($preparedFile['path']) || !rename($preparedFile['temporary'], $preparedFile['path'])) throw new RuntimeException('Kode sudah tersimpan di database, tetapi file final gagal dibuat. File sementara: ' . $preparedFile['temporary']);
        @chmod($preparedFile['path'], 0600);
        $file = $preparedFile['path'];
    }
    $summary['issued'] = count($issued);
    echo json_encode(array('success' => true, 'mode' => 'write', 'summary' => $summary, 'codes_file' => $file, 'message' => $issued ? 'Kode aktivasi siap didistribusikan per desa.' : 'Tidak ada kode baru yang dibuat.'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!$committed && $preparedFile && !empty($preparedFile['temporary'])) @unlink($preparedFile['temporary']);
    fwrite(STDERR, 'Gagal membuat kode aktivasi: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
