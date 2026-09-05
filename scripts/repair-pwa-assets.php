<?php
// CLI recovery also used after deployment: never relax runtime/secret permissions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

try {
    $options = getopt('', array('root:', 'url:'));
    $input = rtrim((string)($options['root'] ?? ''), '/');
    if ($input === '' || is_link($input) || !is_dir($input)) {
        throw new RuntimeException('Isi --root dengan folder document root PWA, bukan symlink.');
    }
    $root = realpath($input);
    if ($root === '/' || !is_file($root . '/index.php') || !is_dir($root . '/application')) {
        throw new RuntimeException('Folder bukan document root PWA SmartDesa.');
    }
    $assets = $root . '/assets';
    if (!is_dir($assets) || is_link($assets)) throw new RuntimeException('Folder assets tidak tersedia atau berupa symlink.');

    $required = array(
        'assets/css/warga.min.css' => array('text/css'),
        'assets/css/community.min.css' => array('text/css'),
        'assets/js/warga.min.js' => array('application/javascript', 'text/javascript'),
        'assets/js/community.min.js' => array('application/javascript', 'text/javascript'),
        'assets/pwa/icon-192.png' => array('image/png'),
        'service-worker.js' => array('application/javascript', 'text/javascript'),
        'manifest.webmanifest' => array('application/manifest+json', 'application/json'),
        'offline.html' => array('text/html')
    );
    // Validate the complete tree before changing any permission. Do not follow links.
    $directories = array($root, $assets);
    $files = array();
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($tree as $entry) {
        if ($entry->isLink()) throw new RuntimeException('Symlink dalam assets ditolak. Periksa folder aset sebelum melanjutkan.');
        $relative = substr($entry->getPathname(), strlen($assets) + 1);
        if (preg_match('~(^|/)\.~', $relative)) continue;
        if ($entry->isDir()) $directories[] = $entry->getPathname();
        elseif ($entry->isFile() && preg_match('/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf|eot)$/iD', $entry->getFilename())) {
            $files[] = $entry->getPathname();
        }
    }
    foreach (array('.htaccess', 'index.php', 'service-worker.js', 'manifest.webmanifest', 'offline.html', 'favicon.ico', 'robots.txt') as $name) {
        $path = $root . '/' . $name;
        if (is_link($path)) throw new RuntimeException('File publik tidak boleh berupa symlink: ' . $name);
        if (is_file($path)) $files[] = $path;
    }
    foreach ($required as $name => $types) {
        if (!is_file($root . '/' . $name) || is_link($root . '/' . $name)) {
            throw new RuntimeException('File publik belum terdeploy: ' . $name . '. Jalankan deployment lengkap.');
        }
    }
    if (is_link($root . '/.env')) throw new RuntimeException('.env tidak boleh berupa symlink.');
    foreach ($directories as $path) {
        if (!chmod($path, 0755)) throw new RuntimeException('Gagal mengatur permission folder publik.');
    }
    foreach (array_unique($files) as $path) {
        if (!chmod($path, 0644)) throw new RuntimeException('Gagal mengatur permission file publik.');
    }
    if (is_file($root . '/.env') && !chmod($root . '/.env', 0600)) throw new RuntimeException('Gagal mengamankan .env.');
    echo "Permission aset publik diperbaiki: folder 755, file 644; .env tetap 600.\n";
    echo "Folder aplikasi, upload, session, dan storage privat tidak diubah.\n";

    $url = rtrim((string)($options['url'] ?? ''), '/');
    if ($url !== '') {
        $parts = parse_url($url);
        if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL dibutuhkan untuk pemeriksaan HTTP.');
        if (!filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('--url harus URL HTTPS publik PWA tanpa kredensial/query.');
        }
        $errors = 0;
        foreach ($required as $name => $types) {
            $curl = curl_init($url . '/' . $name . '?deploy-check=' . time());
            curl_setopt_array($curl, array(
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_WRITEFUNCTION => static function ($handle, $data) { return strlen($data); }
            ));
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $mime = strtolower(trim(explode(';', (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 2)[0]));
            curl_close($curl);
            if ($ok && $status === 200 && in_array($mime, $types, true)) echo 'OK ' . $name . ': HTTP 200, ' . $mime . "\n";
            else {
                $errors++;
                fwrite(STDERR, 'GAGAL ' . $name . ': HTTP ' . $status . ', MIME ' . ($mime ?: '-') . "\n");
            }
        }
        if ($errors) throw new RuntimeException('Aset publik masih gagal. Periksa permission folder induk, aturan .htaccess, atau pembatasan hosting.');
        echo "Semua aset utama PWA dapat dibuka dengan MIME yang benar.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
