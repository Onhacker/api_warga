<?php
/**
 * Provision SmartDesa local installations in bulk.
 *
 * Safe by default: without --write this command only reports the villages
 * that still need an installation credential. With --write, new secrets are
 * written once to a 0600 file outside the public document root.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function load_env_file($path)
{
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
    }
}

function option_values($options, $key)
{
    if (!isset($options[$key])) return array();
    $values = is_array($options[$key]) ? $options[$key] : array($options[$key]);
    $result = array();
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '') $result[] = strtoupper($value);
    }
    return array_values(array_unique($result));
}

function option_value($options, $key)
{
    $values = option_values($options, $key);
    return isset($values[0]) ? $values[0] : '';
}

function raw_option_value($options, $key)
{
    if (!isset($options[$key])) return '';
    $value = is_array($options[$key]) ? end($options[$key]) : $options[$key];
    return trim((string) $value);
}

function uuid_v4()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function urlsafe_secret()
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function encrypt_secret($secret, $appKey)
{
    $tag = '';
    $iv = random_bytes(12);
    $ciphertext = openssl_encrypt(
        $secret,
        'aes-256-gcm',
        hash('sha256', $appKey, true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'smartdesa-warga-api'
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Secret tidak dapat dienkripsi.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function safe_code_fragment($value)
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/[^A-Z0-9]+/', '-', $value);
    return trim((string) $value, '-');
}

function masked_code($code)
{
    $code = (string) $code;
    if (strlen($code) <= 8) return $code;
    return substr($code, 0, 5) . '...' . substr($code, -3);
}

function is_inside($path, $root)
{
    $path = realpath($path);
    $root = realpath($root);
    if ($path === false || $root === false) return false;
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return $path === $root || strpos($path, $prefix) === 0;
}

function prepare_credentials_file($path, array $payload, $publicRoot)
{
    $path = trim((string) $path);
    if ($path === '' || $path === '-') {
        throw new RuntimeException('Gunakan --output dengan path file di luar public_html.');
    }
    if ($path[0] !== DIRECTORY_SEPARATOR) {
        throw new RuntimeException('--output harus berupa path absolut.');
    }

    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Folder output tidak ada atau tidak dapat ditulis: ' . $directory);
    }
    if (is_inside($directory, $publicRoot)) {
        throw new RuntimeException('File kredensial tidak boleh disimpan di public_html.');
    }
    if (file_exists($path)) {
        throw new RuntimeException('File output sudah ada; gunakan nama file baru agar kredensial lama tidak tertimpa.');
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Kredensial belum dapat diformat.');

    $temporary = tempnam($directory, '.smartdesa-credentials-');
    if ($temporary === false) throw new RuntimeException('File sementara output belum dapat dibuat.');
    @chmod($temporary, 0600);
    if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('File sementara kredensial belum dapat disimpan.');
    }
    return array('temporary' => $temporary, 'path' => $path);
}

function finalize_credentials_file(array $prepared)
{
    if (empty($prepared['temporary']) || empty($prepared['path']) || !is_file($prepared['temporary'])) {
        throw new RuntimeException('File sementara kredensial tidak ditemukan.');
    }
    if (file_exists($prepared['path']) || !rename($prepared['temporary'], $prepared['path'])) {
        throw new RuntimeException('File kredensial belum dapat dipindahkan ke lokasi output: ' . $prepared['path']);
    }
    @chmod($prepared['path'], 0600);
    return $prepared['path'];
}

function usage()
{
    return <<<TEXT
Gunakan:
  php tools/provision_installations.php --village=KODE-DESA [--write --output=/path/credentials.json]
  php tools/provision_installations.php --all [--write --output=/path/credentials.json]

Opsi:
  --village=KODE       Satu atau beberapa kode desa yang dipilih.
  --all                Semua desa aktif yang belum memiliki instalasi.
  --write              Simpan kredensial baru ke database.
  --output=PATH        Wajib bersama --write jika ada kredensial baru;
                       path absolut, di luar public_html, permission 0600.
  --help                Tampilkan bantuan.

Tanpa --write, database tidak diubah dan secret tidak dibuat.
TEXT;
}

$options = getopt('', array('all', 'village:', 'write', 'output:', 'help'));
if (isset($options['help'])) {
    fwrite(STDOUT, usage() . PHP_EOL);
    exit(0);
}

$villageCodes = option_values($options, 'village');
$all = isset($options['all']);
$write = isset($options['write']);
$outputPath = raw_option_value($options, 'output');
if (!$all && count($villageCodes) === 0) {
    fwrite(STDERR, usage() . PHP_EOL);
    exit(2);
}
if ($all && count($villageCodes) > 0) {
    fwrite(STDERR, 'Pilih --all atau --village, bukan keduanya.' . PHP_EOL);
    exit(2);
}

load_env_file(__DIR__ . '/../.env');
$appKey = trim((string) getenv('APP_KEY'));
if (strlen($appKey) < 32 || preg_match('/(change-before|ganti-dengan|replace-with)/i', $appKey)) {
    fwrite(STDERR, "APP_KEY belum aman.\n");
    exit(1);
}
if (!function_exists('openssl_encrypt')) {
    fwrite(STDERR, "Ekstensi OpenSSL wajib tersedia.\n");
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

    if ($all) {
        $villageQuery = $pdo->query("SELECT id, village_code, name, district_name, regency_name FROM village_tenants WHERE status = 'active' ORDER BY district_name ASC, name ASC");
        $villages = $villageQuery->fetchAll();
    } else {
        $placeholders = implode(',', array_fill(0, count($villageCodes), '?'));
        $villageQuery = $pdo->prepare("SELECT id, village_code, name, district_name, regency_name FROM village_tenants WHERE status = 'active' AND UPPER(village_code) IN ($placeholders) ORDER BY district_name ASC, name ASC");
        $villageQuery->execute($villageCodes);
        $villages = $villageQuery->fetchAll();
    }

    $foundCodes = array();
    foreach ($villages as $village) $foundCodes[] = strtoupper((string) $village['village_code']);
    $missingCodes = array_values(array_diff($villageCodes, $foundCodes));
    if (count($missingCodes) > 0) {
        throw new RuntimeException('Kode desa aktif tidak ditemukan: ' . implode(', ', $missingCodes));
    }
    if (count($villages) === 0) throw new RuntimeException('Tidak ada desa aktif yang sesuai.');

    $installationQuery = $pdo->query('SELECT id, village_id, installation_code, status, app_version, last_seen_at, last_sync_at FROM village_installations');
    $installationsByVillage = array();
    $usedCodes = array();
    foreach ($installationQuery->fetchAll() as $installation) {
        $villageId = (string) $installation['village_id'];
        if (!isset($installationsByVillage[$villageId])) $installationsByVillage[$villageId] = array();
        $installationsByVillage[$villageId][] = $installation;
        $usedCodes[strtoupper((string) $installation['installation_code'])] = true;
    }

    $summary = array('selected' => count($villages), 'existing' => 0, 'pending' => 0, 'created' => 0, 'multiple_active' => 0);
    $newCredentials = array();
    $plannedRows = array();
    $report = array();
    $pendingRows = array();
    $prefix = 'SDW';

    foreach ($villages as $village) {
        $villageId = (string) $village['id'];
        $existing = isset($installationsByVillage[$villageId]) ? $installationsByVillage[$villageId] : array();
        $active = array_values(array_filter($existing, function ($row) {
            return strtolower(trim((string) $row['status'])) === 'active';
        }));

        if (count($active) > 1) {
            $summary['multiple_active']++;
            $report[] = array(
                'village_code' => $village['village_code'],
                'village_name' => $village['name'],
                'district_name' => $village['district_name'],
                'status' => 'multiple_active',
                'installation_code' => '',
                'app_version' => '',
                'last_seen_at' => '',
                'last_sync_at' => ''
            );
            continue;
        }
        if (count($active) === 1) {
            $summary['existing']++;
            $row = $active[0];
            $report[] = array(
                'village_code' => $village['village_code'],
                'village_name' => $village['name'],
                'district_name' => $village['district_name'],
                'status' => 'active',
                'installation_code' => masked_code($row['installation_code']),
                'app_version' => (string) ($row['app_version'] ?: ''),
                'last_seen_at' => (string) ($row['last_seen_at'] ?: ''),
                'last_sync_at' => (string) ($row['last_sync_at'] ?: '')
            );
            continue;
        }

        $summary['pending']++;
        $pendingRows[] = $village;
        $report[] = array(
            'village_code' => $village['village_code'],
            'village_name' => $village['name'],
            'district_name' => $village['district_name'],
            'status' => count($existing) > 0 ? 'inactive_existing' : 'not_provisioned',
            'installation_code' => '',
            'app_version' => '',
            'last_seen_at' => '',
            'last_sync_at' => ''
        );
    }

    if (!$write) {
        echo json_encode(array('success' => true, 'mode' => 'preview', 'summary' => $summary, 'villages' => $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    if (count($pendingRows) > 0 && $outputPath === '') {
        throw new RuntimeException('--output wajib diisi saat --write membuat kredensial baru.');
    }

    $publicRoot = realpath(__DIR__ . '/..');
    if ($publicRoot === false) throw new RuntimeException('Root aplikasi API tidak ditemukan.');
    foreach ($pendingRows as $village) {
        $fragment = safe_code_fragment($village['village_code']);
        $baseCode = $prefix . '-' . ($fragment !== '' ? $fragment : strtoupper(bin2hex(random_bytes(5))));
        $installationCode = $baseCode;
        $suffix = 1;
        while (isset($usedCodes[strtoupper($installationCode)])) {
            $installationCode = $baseCode . '-' . str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
            $suffix++;
        }
        $usedCodes[strtoupper($installationCode)] = true;
        $secret = urlsafe_secret();
        $plannedRows[] = array(
            'id' => uuid_v4(),
            'village_id' => $village['id'],
            'installation_code' => $installationCode,
            'sync_key_hash' => hash('sha256', $installationCode),
            'sync_secret_hash' => hash('sha256', $secret),
            'sync_secret_encrypted' => encrypt_secret($secret, $appKey)
        );
        $newCredentials[] = array(
            'village_code' => $village['village_code'],
            'village_name' => $village['name'],
            'district_name' => $village['district_name'],
            'regency_name' => $village['regency_name'],
            'installation_code' => $installationCode,
            'secret' => $secret
        );
    }
    $preparedCredentialsFile = null;
    $credentialsFile = null;
    $credentialsCommitted = false;
    if (count($newCredentials) > 0) {
        $preparedCredentialsFile = prepare_credentials_file($outputPath, array(
            'format' => 'smartdesa-warga-installations-v1',
            'created_at' => date('c'),
            'warning' => 'Rahasia hanya ditampilkan pada file ini. Simpan di luar public_html dan hapus setelah didistribusikan secara aman.',
            'credentials' => $newCredentials
        ), $publicRoot);
    }

    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO village_installations (id, village_id, installation_code, sync_key_hash, sync_secret_hash, sync_secret_encrypted, status) VALUES (?, ?, ?, ?, ?, ?, \'active\')');
    foreach ($plannedRows as $planned) {
        $insert->execute(array(
            $planned['id'],
            $planned['village_id'],
            $planned['installation_code'],
            $planned['sync_key_hash'],
            $planned['sync_secret_hash'],
            $planned['sync_secret_encrypted']
        ));
    }
    $pdo->commit();
    $credentialsCommitted = true;
    $summary['created'] = count($plannedRows);
    if ($preparedCredentialsFile !== null) {
        try {
            $credentialsFile = finalize_credentials_file($preparedCredentialsFile);
        } catch (Throwable $finalizeError) {
            throw new RuntimeException($finalizeError->getMessage() . ' File sementara dipertahankan di: ' . $preparedCredentialsFile['temporary']);
        }
    }

    echo json_encode(array(
        'success' => true,
        'mode' => 'write',
        'summary' => $summary,
        'credentials_file' => $credentialsFile,
        'message' => count($newCredentials) > 0 ? 'Kredensial baru dibuat satu kali. Distribusikan setiap baris hanya ke desa yang sesuai.' : 'Tidak ada kredensial baru yang dibuat.'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (empty($credentialsCommitted) && isset($preparedCredentialsFile) && is_array($preparedCredentialsFile) && !empty($preparedCredentialsFile['temporary'])) {
        @unlink($preparedCredentialsFile['temporary']);
    }
    fwrite(STDERR, 'Gagal provisioning: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
