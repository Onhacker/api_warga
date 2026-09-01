<?php
/**
 * Configure the one-time bootstrap shared by the central Warga API and the
 * official SmartDesa installer builder.
 *
 * This command is for the central operator only. It never prints the secret
 * and never writes the builder snippet below public_html.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function load_env_values($path)
{
    if (!is_readable($path)) return false;
    $values = array();
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') $values[$key] = $value;
    }
    return $values;
}

function option_value($options, $key)
{
    if (!isset($options[$key])) return '';
    $value = is_array($options[$key]) ? end($options[$key]) : $options[$key];
    return trim((string) $value);
}

function urlsafe_random($bytes)
{
    return rtrim(strtr(base64_encode(random_bytes((int) $bytes)), '+/', '-_'), '=');
}

function valid_bootstrap($key, $secret)
{
    return preg_match('/^[A-Za-z0-9._-]{16,128}$/', (string) $key) === 1
        && strlen((string) $secret) >= 32
        && !hash_equals((string) $key, (string) $secret)
        && !preg_match('/replace-with|ganti-dengan|change-before|example/i', (string) $key . (string) $secret)
        && !preg_match('/[\r\n]/', (string) $key . (string) $secret);
}

function is_inside_path($path, $root)
{
    $path = realpath($path);
    $root = realpath($root);
    if ($path === false || $root === false) return false;
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || strpos($path, $root . '/') === 0;
}

function is_absolute_path($path)
{
    $path = trim((string) $path);
    return $path !== '' && preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{1,2})/', $path) === 1;
}

function mask_value($value)
{
    $value = (string) $value;
    if (strlen($value) <= 8) return str_repeat('*', strlen($value));
    return substr($value, 0, 4) . '...' . substr($value, -4);
}

function update_env_content($content, array $updates)
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

function prepare_private_file($path, $content, $public_root, $allow_inside = false)
{
    $path = trim((string) $path);
    if (!is_absolute_path($path)) {
        throw new RuntimeException('Path output harus absolut.');
    }
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Folder output tidak ada atau tidak dapat ditulis: ' . $directory);
    }
    if (!$allow_inside && is_inside_path($directory, $public_root)) {
        throw new RuntimeException('File bootstrap tidak boleh disimpan di public_html.');
    }
    if (is_link($path)) {
        throw new RuntimeException('Path output berupa symlink dan ditolak: ' . $path);
    }
    $temporary = tempnam($directory, '.smartdesa-auto-enroll-');
    if ($temporary === false) throw new RuntimeException('File sementara tidak dapat dibuat.');
    @chmod($temporary, 0600);
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('File sementara tidak dapat ditulis.');
    }
    return array('temporary' => $temporary, 'path' => $path);
}

function commit_private_file(array $prepared)
{
    if (!isset($prepared['temporary'], $prepared['path']) || !is_file($prepared['temporary'])) {
        throw new RuntimeException('File sementara bootstrap tidak ditemukan.');
    }
    if (!@rename($prepared['temporary'], $prepared['path'])) {
        @unlink($prepared['temporary']);
        throw new RuntimeException('File bootstrap tidak dapat dipindahkan ke lokasi final: ' . $prepared['path']);
    }
    @chmod($prepared['path'], 0600);
    return $prepared['path'];
}

function usage()
{
    return <<<TEXT
Gunakan:
  php tools/configure_auto_enrollment.php --env=/path/.env --builder-output=/path/builder.env [--write]

Opsi:
  --env=PATH              .env API pusat yang akan dikonfigurasi.
  --builder-output=PATH   Snippet privat untuk workstation builder, di luar public_html.
  --write                 Simpan konfigurasi. Tanpa opsi ini hanya preview.
  --rotate                Buat pasangan baru; semua installer lama harus diperbarui.
  --help                  Tampilkan bantuan.

Perintah ini cukup dijalankan sekali oleh pengelola pusat. Operator desa tidak
perlu menerima snippet atau memasukkan kode aktivasi.
TEXT;
}

$options = getopt('', array('env:', 'builder-output:', 'write', 'rotate', 'help'));
if (isset($options['help'])) {
    fwrite(STDOUT, usage() . PHP_EOL);
    exit(0);
}

$env_path = option_value($options, 'env');
$output_path = option_value($options, 'builder-output');
$write = isset($options['write']);
$rotate = isset($options['rotate']);
if ($env_path === '' || $output_path === '') {
    fwrite(STDERR, usage() . PHP_EOL);
    exit(2);
}
if (!is_file($env_path) || !is_readable($env_path)) {
    fwrite(STDERR, 'File API .env tidak ditemukan atau tidak dapat dibaca: ' . $env_path . PHP_EOL);
    exit(1);
}

$values = load_env_values($env_path);
if (!is_array($values)) {
    fwrite(STDERR, 'Environment API tidak dapat dibaca.' . PHP_EOL);
    exit(1);
}
$current_key = isset($values['WARGA_AUTO_ENROLL_KEY']) ? trim((string) $values['WARGA_AUTO_ENROLL_KEY']) : '';
$current_secret = isset($values['WARGA_AUTO_ENROLL_SECRET']) ? trim((string) $values['WARGA_AUTO_ENROLL_SECRET']) : '';
$ready = valid_bootstrap($current_key, $current_secret);
if ($ready && $rotate) {
    $current_key = '';
    $current_secret = '';
    $ready = false;
}
$key = $ready ? $current_key : 'sdw_auto_' . urlsafe_random(24);
$secret = $ready ? $current_secret : urlsafe_random(48);
$updates = array(
    'WARGA_AUTO_ENROLL_ENABLED' => '1',
    'WARGA_AUTO_ENROLL_KEY' => $key,
    'WARGA_AUTO_ENROLL_SECRET' => $secret,
    'WARGA_AUTO_ENROLL_TTL' => isset($values['WARGA_AUTO_ENROLL_TTL']) && ctype_digit((string) $values['WARGA_AUTO_ENROLL_TTL'])
        ? max(60, min(900, (int) $values['WARGA_AUTO_ENROLL_TTL'])) : '300'
);

echo json_encode(array(
    'success' => true,
    'mode' => $write ? 'write' : 'preview',
    'env' => $env_path,
    'bootstrap_key' => mask_value($key),
    'bootstrap_secret_sha256' => hash('sha256', $secret),
    'rotated' => !$ready,
    'message' => $write ? 'Konfigurasi akan disimpan tanpa menampilkan secret.' : 'Preview saja; gunakan --write untuk menyimpan.'
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (!$write) exit(0);

$content = file_get_contents($env_path);
if ($content === false) {
    fwrite(STDERR, 'Isi .env API tidak dapat dibaca ulang.' . PHP_EOL);
    exit(1);
}
$public_root = realpath(dirname($env_path));
if ($public_root === false) $public_root = dirname($env_path);
$builder_content = "# Private builder bootstrap. Do not commit or upload to public_html.\n"
    . 'SMARTDESA_WARGA_API_URL=https://api-warga-smartdesa.mediaverse.co.id/v1' . "\n"
    . "SMARTDESA_WARGA_AUTO_ENROLL_ENABLED=1\n"
    . 'SMARTDESA_WARGA_AUTO_ENROLL_KEY=' . $key . "\n"
    . 'SMARTDESA_WARGA_AUTO_ENROLL_SECRET=' . $secret . "\n";
$prepared_env = null;
$prepared_builder = null;
try {
    // The API .env itself is intentionally inside its public root; only this
    // target is allowed there. The builder output remains strictly private.
    $prepared_env = prepare_private_file($env_path, update_env_content($content, $updates), $public_root, true);
    $prepared_builder = prepare_private_file($output_path, $builder_content, $public_root);
    commit_private_file($prepared_env);
    commit_private_file($prepared_builder);
    echo json_encode(array(
        'success' => true,
        'mode' => 'write',
        'api_env' => $env_path,
        'builder_file' => $output_path,
        'permissions' => '0600',
        'bootstrap_key' => mask_value($key),
        'bootstrap_secret_sha256' => hash('sha256', $secret),
        'message' => 'API pusat dan snippet builder sudah menggunakan pasangan bootstrap yang sama.'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($prepared_env && is_file($prepared_env['temporary'])) @unlink($prepared_env['temporary']);
    if ($prepared_builder && is_file($prepared_builder['temporary'])) @unlink($prepared_builder['temporary']);
    fwrite(STDERR, 'Gagal menyimpan bootstrap: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
