<?php
/**
 * Reset operational Warga data for a controlled end-to-end test.
 *
 * This file is CLI-only and deliberately requires an explicit confirmation
 * token. It preserves regional master data, service catalogues, roles,
 * installation credentials, marketplace data, and settings. Create a
 * mysqldump backup before running the destructive mode. Marketplace data is
 * protected by default because its owner rows are linked to users.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', array('env::', 'confirm::', 'report-only', 'include-marketplace', 'help'));
$envPath = isset($options['env']) && is_string($options['env']) && $options['env'] !== ''
    ? $options['env'] : getenv('WARGA_ENV');
$confirm = isset($options['confirm']) && is_string($options['confirm']) ? $options['confirm'] : '';
$reportOnly = array_key_exists('report-only', $options);
$includeMarketplace = array_key_exists('include-marketplace', $options);

if (array_key_exists('help', $options) || $envPath === FALSE || trim((string) $envPath) === '') {
    fwrite(STDOUT, "Penggunaan:\n");
    fwrite(STDOUT, "  php tools/reset_warga_test_data.php --env=/path/.env --report-only\n");
    fwrite(STDOUT, "  php tools/reset_warga_test_data.php --env=/path/.env --confirm=RESET-WARGA-TEST-DATA\n");
    fwrite(STDOUT, "  php tools/reset_warga_test_data.php --env=/path/.env --include-marketplace --confirm=RESET-WARGA-TEST-DATA\n");
    fwrite(STDOUT, "\nDefault: data Pasar Digital dipertahankan. Gunakan --include-marketplace hanya jika memang ingin menghapusnya.\n");
    exit($envPath === FALSE || trim((string) $envPath) === '' ? 2 : 0);
}

if (!$reportOnly && $confirm !== 'RESET-WARGA-TEST-DATA') {
    fwrite(STDERR, "Mode hapus memerlukan --confirm=RESET-WARGA-TEST-DATA.\n");
    exit(2);
}

function reset_read_env($path)
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('File .env tidak dapat dibaca: ' . $path);
    }
    $values = array();
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, 'export ') === 0) $line = substr($line, 7);
        $separator = strpos($line, '=');
        if ($separator === FALSE) continue;
        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if ($value !== '' && strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') $values[$key] = $value;
    }
    return $values;
}

function reset_table_name($table)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Nama tabel tidak valid.');
    }
    return '`' . $table . '`';
}

function reset_existing_tables($db, $database)
{
    $database = $db->real_escape_string($database);
    $result = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$database}'");
    if (!$result) throw new RuntimeException('Tidak dapat membaca daftar tabel: ' . $db->error);
    $tables = array();
    while ($row = $result->fetch_assoc()) $tables[(string) $row['TABLE_NAME']] = TRUE;
    $result->free();
    return $tables;
}

function reset_count($db, $table)
{
    $result = $db->query('SELECT COUNT(*) AS total FROM ' . reset_table_name($table));
    if (!$result) throw new RuntimeException('Gagal menghitung ' . $table . ': ' . $db->error);
    $row = $result->fetch_assoc();
    $result->free();
    return (int) ($row['total'] ?? 0);
}

function reset_duplicate_groups($db, $table)
{
    $quoted = reset_table_name($table);
    $sql = "SELECT COUNT(*) AS total FROM ("
        . "SELECT nik_hash FROM {$quoted} "
        . "WHERE nik_hash IS NOT NULL AND nik_hash <> '' "
        . "GROUP BY nik_hash HAVING COUNT(*) > 1"
        . ") AS duplicate_groups";
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException('Gagal memeriksa duplikasi ' . $table . ': ' . $db->error);
    $row = $result->fetch_assoc();
    $result->free();
    return (int) ($row['total'] ?? 0);
}

function reset_user_foreign_keys($db, $database)
{
    $schema = $db->real_escape_string($database);
    $result = $db->query(
        "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE "
        . "WHERE REFERENCED_TABLE_SCHEMA='{$schema}' AND REFERENCED_TABLE_NAME='users'"
    );
    if (!$result) throw new RuntimeException('Tidak dapat memeriksa relasi akun: ' . $db->error);
    $tables = array();
    while ($row = $result->fetch_assoc()) $tables[] = (string) $row['TABLE_NAME'];
    $result->free();
    sort($tables);
    return array_values(array_unique($tables));
}

try {
    $env = reset_read_env((string) $envPath);
    foreach (array('DB_HOST', 'DB_USER', 'DB_NAME') as $required) {
        if (!isset($env[$required]) || trim((string) $env[$required]) === '') {
            throw new RuntimeException($required . ' belum terisi pada .env.');
        }
    }
    $dbName = (string) $env['DB_NAME'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) throw new RuntimeException('Nama database tidak valid.');
    $port = isset($env['DB_PORT']) && (int) $env['DB_PORT'] > 0 ? (int) $env['DB_PORT'] : 3306;
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = new mysqli((string) $env['DB_HOST'], (string) $env['DB_USER'], (string) ($env['DB_PASS'] ?? ''), $dbName, $port);
    if ($db->connect_errno) throw new RuntimeException('Koneksi database gagal: ' . $db->connect_error);
    if (!$db->set_charset('utf8mb4')) throw new RuntimeException('Charset database belum dapat diatur.');

    $existing = reset_existing_tables($db, $dbName);
    $trackedTables = array(
        'users', 'citizen_profiles', 'village_resident_directory',
        'village_resident_directory_staging', 'village_resident_snapshots',
        'village_resident_snapshot_batches', 'resident_verification_attempts',
        'service_requests', 'request_documents', 'request_status_history',
        'notifications', 'warga_notification_targets', 'warga_push_subscriptions',
        'warga_push_deliveries', 'warga_announcements', 'warga_complaints',
        'warga_complaint_replies', 'warga_staff_sources', 'sync_messages',
        'api_request_nonces', 'installation_enrollment_attempts',
        'auto_enrollment_nonces', 'warga_village_config_versions',
        'login_failures', 'marketplace_stores', 'marketplace_products',
        'marketplace_product_images', 'marketplace_product_reviews'
    );
    $counts = array();
    foreach ($trackedTables as $table) {
        if (isset($existing[$table])) $counts[$table] = reset_count($db, $table);
    }

    fwrite(STDOUT, "Database: {$dbName}\n");
    fwrite(STDOUT, "Mode: " . ($reportOnly ? 'pemeriksaan saja' : 'RESET DATA UJI') . "\n\n");
    fwrite(STDOUT, "Jumlah data yang akan diproses:\n");
    foreach ($counts as $table => $count) fwrite(STDOUT, sprintf("  %-38s %d\n", $table, $count));

    foreach (array('citizen_profiles', 'village_resident_directory') as $table) {
        if (isset($existing[$table])) {
            fwrite(STDOUT, sprintf("  konflik hash NIK %-25s %d grup\n", $table, reset_duplicate_groups($db, $table)));
        }
    }

    $marketplaceTables = array(
        'marketplace_product_reviews', 'marketplace_product_images',
        'marketplace_products', 'marketplace_stores'
    );
    $marketplaceRows = 0;
    foreach ($marketplaceTables as $table) {
        if (isset($counts[$table])) $marketplaceRows += $counts[$table];
    }
    if ($marketplaceRows > 0) {
        fwrite(STDOUT, "\nPERINGATAN: ditemukan {$marketplaceRows} baris data Pasar Digital.\n");
        if (!$includeMarketplace && !$reportOnly) {
            throw new RuntimeException(
                'Reset dibatalkan agar data Pasar Digital tidak ikut terhapus. '
                . 'Gunakan --include-marketplace setelah memastikan penghapusan tersebut memang diinginkan.'
            );
        }
        if ($includeMarketplace) {
            fwrite(STDOUT, "Opsi --include-marketplace aktif: data Pasar Digital akan ikut dikosongkan.\n");
        } else {
            fwrite(STDOUT, "Mode pemeriksaan: data Pasar Digital akan dipertahankan.\n");
        }
    }

    $allowedUserReferences = array(
        'citizen_profiles', 'service_requests', 'request_documents',
        'request_status_history', 'notifications', 'warga_announcements',
        'warga_complaints', 'warga_complaint_replies', 'warga_push_subscriptions',
        'warga_staff_sources', 'settings', 'marketplace_stores',
        'marketplace_products', 'marketplace_product_reviews'
    );
    $unknownReferences = array_diff(reset_user_foreign_keys($db, $dbName), $allowedUserReferences);
    if (!empty($unknownReferences)) {
        throw new RuntimeException('Ditemukan tabel relasi akun yang belum dikenal: ' . implode(', ', $unknownReferences));
    }

    if ($reportOnly) {
        fwrite(STDOUT, "\nPemeriksaan selesai. Tidak ada data yang diubah.\n");
        $db->close();
        exit(0);
    }

    $deleteOrder = array(
        'warga_push_deliveries', 'warga_push_subscriptions',
        'warga_notification_targets', 'notifications',
        'request_documents', 'request_status_history', 'service_requests',
        'warga_complaint_replies', 'warga_complaints', 'warga_announcements',
        'warga_staff_sources',
        'citizen_profiles', 'api_request_nonces', 'sync_messages',
        'village_resident_directory_staging', 'village_resident_snapshot_batches',
        'village_resident_snapshots', 'village_resident_directory',
        'resident_verification_attempts', 'installation_enrollment_attempts',
        'auto_enrollment_nonces', 'warga_village_config_versions',
        'login_failures', 'users'
    );
    if ($includeMarketplace) {
        $marketplaceDeleteOrder = array(
            'marketplace_product_reviews', 'marketplace_product_images',
            'marketplace_products', 'marketplace_stores'
        );
        $insertAt = array_search('warga_staff_sources', $deleteOrder, TRUE);
        $deleteOrder = array_merge(
            array_slice($deleteOrder, 0, $insertAt),
            $marketplaceDeleteOrder,
            array_slice($deleteOrder, $insertAt)
        );
    }

    if (!$db->begin_transaction()) throw new RuntimeException('Transaksi reset belum dapat dimulai.');
    foreach ($deleteOrder as $table) {
        if (!isset($existing[$table])) continue;
        if (!$db->query('DELETE FROM ' . reset_table_name($table))) {
            $db->rollback();
            throw new RuntimeException('Gagal mengosongkan ' . $table . ': ' . $db->error);
        }
    }
    if (!$db->commit()) throw new RuntimeException('Transaksi reset belum dapat diselesaikan.');

    fwrite(STDOUT, "\nReset selesai. Data operasional dan seluruh akun telah dikosongkan.\n");
    fwrite(STDOUT, "Dipertahankan: village_tenants, roles, service_types, katalog layanan, village_installations, settings.\n");
    fwrite(STDOUT, $includeMarketplace
        ? "Data Pasar Digital juga telah dikosongkan (--include-marketplace).\n"
        : "Data Pasar Digital dipertahankan.\n");
    fwrite(STDOUT, "Lampiran berkas di storage privat tidak dihapus oleh alat ini.\n");
    $db->close();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "GAGAL: " . $exception->getMessage() . "\n");
    exit(1);
}
