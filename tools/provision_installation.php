<?php
/**
 * Generate or provision one SmartDesa installation credential.
 * Run from CLI only. The generated secret is shown once.
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

function option_value($options, $key)
{
    return isset($options[$key]) && is_string($options[$key]) ? trim($options[$key]) : '';
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

$options = getopt('', array('village:', 'code::', 'secret::', 'write', 'env:', 'help'));
if (isset($options['help']) || !option_value($options, 'village')) {
    fwrite(STDOUT, "Gunakan: php tools/provision_installation.php --village=KODE-DESA [--code=KODE] [--secret=SECRET] [--write] [--env=/path/.env]\n");
    fwrite(STDOUT, "Tanpa --write hanya menampilkan preview dan tidak mengubah database.\n");
    exit(isset($options['help']) ? 0 : 2);
}
$envPath = raw_option_value($options, 'env');
if ($envPath === '') $envPath = __DIR__ . '/../.env';
if (!load_env_file($envPath, raw_option_value($options, 'env') !== '')) {
    fwrite(STDERR, 'File .env tidak ditemukan atau tidak dapat dibaca: ' . $envPath . PHP_EOL);
    exit(1);
}
$appKey = (string) getenv('APP_KEY');
if (strlen($appKey) < 32 || stripos($appKey, 'change-before') !== false || stripos($appKey, 'ganti-dengan') !== false || stripos($appKey, 'replace-with') !== false) { fwrite(STDERR, 'APP_KEY belum aman pada ' . $envPath . ". Jangan mengganti APP_KEY produksi yang sudah dipakai database.\n"); exit(1); }
$villageCode = strtoupper(option_value($options, 'village'));
$installationCode = option_value($options, 'code') ?: 'SDW-' . strtoupper(bin2hex(random_bytes(6)));
$secret = option_value($options, 'secret') ?: urlsafe_secret();
if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $installationCode) || strlen($secret) < 32) { fwrite(STDERR, "Kode atau secret tidak memenuhi format minimum.\n"); exit(1); }
$tag = '';
$ciphertext = openssl_encrypt($secret, 'aes-256-gcm', hash('sha256', $appKey, TRUE), OPENSSL_RAW_DATA, $iv = random_bytes(12), $tag, 'smartdesa-warga-api');
if ($ciphertext === FALSE || strlen($tag) !== 16) { fwrite(STDERR, "Secret tidak dapat dienkripsi.\n"); exit(1); }
$encrypted = base64_encode($iv . $tag . $ciphertext);
$result = array('village_code' => $villageCode, 'installation_code' => $installationCode, 'secret' => $secret, 'sync_key_hash' => hash('sha256', $installationCode), 'sync_secret_hash' => hash('sha256', $secret), 'sync_secret_encrypted' => $encrypted, 'write' => isset($options['write']));
if (!isset($options['write'])) { echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(0); }

$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'smartdesa_warga') . ';charset=utf8mb4';
try {
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $village = $pdo->prepare("SELECT id FROM village_tenants WHERE village_code = :code AND status = 'active' LIMIT 1");
    $village->execute(array(':code' => $villageCode));
    $villageRow = $village->fetch();
    if (!$villageRow) throw new RuntimeException('Kode desa aktif tidak ditemukan.');
    $insert = $pdo->prepare('INSERT INTO village_installations (id, village_id, installation_code, sync_key_hash, sync_secret_hash, sync_secret_encrypted, status) VALUES (:id, :village_id, :code, :key_hash, :secret_hash, :encrypted, \'active\')');
    $insert->execute(array(':id' => uuid_v4(), ':village_id' => $villageRow['id'], ':code' => $installationCode, ':key_hash' => hash('sha256', $installationCode), ':secret_hash' => hash('sha256', $secret), ':encrypted' => $encrypted));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Gagal provisioning: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
