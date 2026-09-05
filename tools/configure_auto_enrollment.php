<?php
/**
 * Configure grant-based automatic enrollment for the central Warga API.
 *
 * The signing secret is shared only by the activation server and this API.
 * It is deliberately never written to the builder snippet or an installer.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function warga_config_option($options, $key, $default = '')
{
    if (!isset($options[$key])) return $default;
    $value = is_array($options[$key]) ? end($options[$key]) : $options[$key];
    return trim((string) $value);
}

function warga_config_env($path)
{
    if (!is_file($path) || !is_readable($path) || is_link($path)) return false;
    $values = array();
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return false;
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"')
            || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') $values[$key] = $value;
    }
    return $values;
}

function warga_config_valid_secret($secret)
{
    return strlen((string) $secret) >= 32
        && strlen((string) $secret) <= 512
        && !preg_match('/replace-with|ganti-dengan|change-before|example/i', (string) $secret)
        && !preg_match('/[\r\n]/', (string) $secret);
}

function warga_config_absolute($path)
{
    return trim((string) $path) !== ''
        && preg_match('/^(?:[A-Za-z]:[\\\/]|[\\\/]{1,2})/', trim((string) $path)) === 1;
}

function warga_config_inside($path, $root)
{
    $path = rtrim(str_replace('\\', '/', (string) $path), '/');
    $root = rtrim(str_replace('\\', '/', (string) $root), '/');
    if ($path === '' || $root === '') return false;
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || strpos($path, $root . '/') === 0;
}

function warga_config_update_env($content, array $updates)
{
    $seen = array();
    $lines = preg_split('/\r\n|\n|\r/', (string) $content);
    foreach ($lines as &$line) {
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match)) continue;
        $key = $match[1];
        if (!array_key_exists($key, $updates)) continue;
        $line = $key . '=' . str_replace(array("\r", "\n"), '', (string) $updates[$key]);
        $seen[$key] = true;
    }
    unset($line);
    foreach ($updates as $key => $value) {
        if (isset($seen[$key])) continue;
        $lines[] = $key . '=' . str_replace(array("\r", "\n"), '', (string) $value);
    }
    return implode("\n", $lines) . "\n";
}

function warga_config_atomic_write($path, $content, $mode = 0600)
{
    if (!warga_config_absolute($path) || is_link($path)) {
        throw new RuntimeException('Target output harus berupa file absolut dan bukan symlink.');
    }
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Folder output tidak ada atau tidak dapat ditulis: ' . $directory);
    }
    $temporary = tempnam($directory, '.smartdesa-grant-');
    if ($temporary === false) throw new RuntimeException('File sementara tidak dapat dibuat.');
    @chmod($temporary, $mode);
    if (file_put_contents($temporary, $content, LOCK_EX) === false
        || !@chmod($temporary, $mode)
        || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('File konfigurasi tidak dapat disimpan.');
    }
    @chmod($path, $mode);
}

function warga_config_hash($value)
{
    return hash('sha256', (string) $value);
}

function warga_config_usage()
{
    return <<<TEXT
Gunakan:
  php tools/configure_auto_enrollment.php --env=/path/api/.env --builder-output=/path/private/warga-builder.env [--write]

Opsi:
  --env=PATH                 .env API Warga pusat.
  --builder-output=PATH      Snippet non-rahasia untuk workstation builder.
  --api-url=URL              URL API Warga; default domain produksi.
  --grant-secret=VALUE       Secret grant minimal 32 karakter.
  --grant-secret-file=PATH   File privat yang berisi secret grant.
  --rotate                    Buat secret baru; sinkronkan juga ke server aktivasi.
  --write                    Simpan perubahan; tanpa ini hanya preview.
  --help                     Tampilkan bantuan.

Secret grant tidak pernah dimasukkan ke builder atau installer. Desa tidak perlu
menerima snippet maupun memasukkan kredensial API.
TEXT;
}

$options = getopt('', array(
    'env:', 'builder-output:', 'api-url:', 'grant-secret:',
    'grant-secret-file:', 'rotate', 'write', 'help'
));
if (isset($options['help'])) {
    fwrite(STDOUT, warga_config_usage() . PHP_EOL);
    exit(0);
}

$env_path = warga_config_option($options, 'env');
$builder_path = warga_config_option($options, 'builder-output');
$api_url = rtrim(warga_config_option($options, 'api-url', 'https://api-warga-smartdesa.mediaverse.co.id/v1'), '/');
$write = isset($options['write']);
$rotate = isset($options['rotate']);
if ($env_path === '' || $builder_path === '') {
    fwrite(STDERR, warga_config_usage() . PHP_EOL);
    exit(2);
}
if (!warga_config_absolute($env_path) || !warga_config_absolute($builder_path)) {
    fwrite(STDERR, "Path .env dan output builder harus absolut.\n");
    exit(2);
}
if (filter_var($api_url, FILTER_VALIDATE_URL) === false
    || strtolower((string) parse_url($api_url, PHP_URL_SCHEME)) !== 'https'
    || preg_match('/[\r\n]/', $api_url)) {
    fwrite(STDERR, "URL API harus HTTPS dan valid.\n");
    exit(2);
}
if (!is_file($env_path) || !is_readable($env_path) || is_link($env_path)) {
    fwrite(STDERR, "File .env API tidak ditemukan, tidak dapat dibaca, atau berupa symlink.\n");
    exit(1);
}

$values = warga_config_env($env_path);
if (!is_array($values)) {
    fwrite(STDERR, "Environment API tidak dapat dibaca.\n");
    exit(1);
}
$current = trim((string) (isset($values['WARGA_ENROLL_GRANT_SECRET']) ? $values['WARGA_ENROLL_GRANT_SECRET'] : ''));
$provided = warga_config_option($options, 'grant-secret');
$secret_file = warga_config_option($options, 'grant-secret-file');
if ($provided !== '' && $secret_file !== '') {
    fwrite(STDERR, "Gunakan --grant-secret atau --grant-secret-file, bukan keduanya.\n");
    exit(2);
}
if ($secret_file !== '') {
    if (!warga_config_absolute($secret_file) || !is_file($secret_file) || !is_readable($secret_file) || is_link($secret_file)) {
        fwrite(STDERR, "File secret grant tidak ditemukan atau tidak aman.\n");
        exit(1);
    }
    $provided = trim((string) file_get_contents($secret_file));
}
if ($provided !== '' && !warga_config_valid_secret($provided)) {
    fwrite(STDERR, "Secret grant yang diberikan tidak valid.\n");
    exit(2);
}
if ($provided !== '') {
    $grant_secret = $provided;
} elseif (!$rotate && warga_config_valid_secret($current)) {
    $grant_secret = $current;
} else {
    try {
        $grant_secret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    } catch (Exception $error) {
        fwrite(STDERR, "Secret grant baru belum dapat dibuat.\n");
        exit(1);
    }
}

$ttl = isset($values['WARGA_AUTO_ENROLL_TTL']) && ctype_digit((string) $values['WARGA_AUTO_ENROLL_TTL'])
    ? max(60, min(900, (int) $values['WARGA_AUTO_ENROLL_TTL'])) : 300;
$updates = array(
    'WARGA_AUTO_ENROLL_ENABLED' => '1',
    'WARGA_AUTO_ENROLL_LEGACY_ENABLED' => '0',
    'WARGA_AUTO_ENROLL_KEY' => '',
    'WARGA_AUTO_ENROLL_SECRET' => '',
    'WARGA_AUTO_ENROLL_TTL' => (string) $ttl,
    'WARGA_ENROLL_GRANT_SECRET' => $grant_secret
);
$builder_content = "# Public builder configuration. No shared secret is stored here.\n"
    . 'SMARTDESA_WARGA_API_URL=' . $api_url . "\n"
    . "SMARTDESA_WARGA_AUTO_ENROLL_ENABLED=1\n"
    . "SMARTDESA_WARGA_ENROLLMENT_MODE=activation_grant\n"
    . "SMARTDESA_WARGA_AUTO_ENROLL_KEY=\n"
    . "SMARTDESA_WARGA_AUTO_ENROLL_SECRET=\n";
$public_root = realpath(dirname($env_path));
$builder_directory = realpath(dirname($builder_path));
if ($public_root !== false && $builder_directory !== false
    && warga_config_inside($builder_directory, $public_root)) {
    fwrite(STDERR, "Output builder harus berada di luar public_html API.\n");
    exit(1);
}

$result = array(
    'success' => true,
    'mode' => $write ? 'write' : 'preview',
    'api_env' => $env_path,
    'builder_output' => $builder_path,
    'api_url' => $api_url,
    'enrollment_mode' => 'activation_grant',
    'legacy_bootstrap' => 'disabled',
    'grant_secret_sha256' => warga_config_hash($grant_secret),
    'rotated' => $rotate || !warga_config_valid_secret($current),
    'message' => $write
        ? 'API dikonfigurasi tanpa menaruh secret pada builder atau installer.'
        : 'Preview saja; gunakan --write untuk menyimpan.'
);
if (!$write) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$env_content = file_get_contents($env_path);
if ($env_content === false) {
    fwrite(STDERR, "Isi .env API tidak dapat dibaca ulang.\n");
    exit(1);
}
try {
    warga_config_atomic_write($env_path, warga_config_update_env($env_content, $updates), 0600);
    warga_config_atomic_write($builder_path, $builder_content, 0600);
} catch (Throwable $error) {
    fwrite(STDERR, 'Gagal menyimpan konfigurasi: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
