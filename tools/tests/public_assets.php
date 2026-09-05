<?php
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
$testDir = sys_get_temp_dir() . '/sdw-public-assets-' . bin2hex(random_bytes(6));
$root = $testDir . '/public_html';
$script = dirname(__DIR__, 2) . '/scripts/repair-pwa-assets.php';
$count = 0;
function check($condition, $label) {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
    $count++;
}
function run_repair($path) {
    global $script;
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg('--root=' . $path);
    exec($command . ' 2>&1', $output, $code);
    return array('code'=>$code, 'output'=>implode("\n", $output));
}
function mode($path) { clearstatcache(); return fileperms($path) & 0777; }
$exitCode = 0;
try {
    $public = array('index.php', '.htaccess', 'service-worker.js', 'manifest.webmanifest', 'offline.html',
        'assets/css/warga.min.css', 'assets/css/community.min.css', 'assets/js/warga.min.js',
        'assets/js/community.min.js', 'assets/pwa/icon-192.png', 'assets/fonts/font.woff2');
    $private = array('.env', 'application/logs/private.log', 'application/sessions/session-test',
        'storage/backup.sql', 'uploads/requests/attachment.png', 'assets/.env', 'assets/private.php',
        'assets/.hidden/secret.js');
    foreach (array_merge($public, $private) as $file) {
        if (!is_dir(dirname($root . '/' . $file))) mkdir(dirname($root . '/' . $file), 0700, true);
        file_put_contents($root . '/' . $file, 'fixture: ' . $file);
    }
    check(mode($root . '/assets/css/community.min.css') === 0600 && mode($root . '/assets/css') === 0700,
        'reproduces restrictive deploy umask');
    $before = array();
    foreach (array_merge($public, $private) as $file) $before[$file] = hash_file('sha256', $root . '/' . $file);
    $result = run_repair($root);
    check($result['code'] === 0, 'recovery succeeds with restrictive permissions');
    foreach ($public as $file) check(mode($root . '/' . $file) === 0644, 'web-readable ' . $file);
    foreach (array('', '/assets', '/assets/css', '/assets/js', '/assets/pwa', '/assets/fonts') as $dir) {
        check(mode($root . $dir) === 0755, 'traversable public directory ' . ($dir ?: '/'));
    }
    foreach ($private as $file) check(mode($root . '/' . $file) === 0600, 'private permission retained ' . $file);
    foreach (array('application', 'application/logs', 'application/sessions', 'storage', 'uploads/requests', 'assets/.hidden') as $dir) {
        check(mode($root . '/' . $dir) === 0700, 'private directory retained ' . $dir);
    }
    foreach ($before as $file=>$hash) check(hash_file('sha256', $root . '/' . $file) === $hash, 'content unchanged ' . $file);
    check(run_repair($root)['code'] === 0, 'repeated repair succeeds');
    rename($root . '/assets/css/community.min.css', $root . '/assets/css/renamed.css');
    check(run_repair($root)['code'] === 1, 'missing release file requires full deploy');
    rename($root . '/assets/css/renamed.css', $root . '/assets/css/community.min.css');
    symlink($root . '/.env', $root . '/assets/linked.css');
    chmod($root . '/assets/css/community.min.css', 0600);
    check(run_repair($root)['code'] === 1 && mode($root . '/.env') === 0600
        && mode($root . '/assets/css/community.min.css') === 0600, 'symlink aborts before permission changes');
    unlink($root . '/assets/linked.css');
    symlink($root, $testDir . '/linked-root');
    check(run_repair($testDir . '/linked-root')['code'] === 1, 'symlink root rejected');
    check(run_repair($testDir)['code'] === 1, 'unrelated root rejected');
    echo 'OK: ' . $count . " public asset permission checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_dir($testDir)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
            else unlink($item->getPathname());
        }
        rmdir($testDir);
    }
}
exit($exitCode);
