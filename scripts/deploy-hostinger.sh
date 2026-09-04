#!/usr/bin/env bash
set -Eeuo pipefail

API_REPO="${API_REPO:-$HOME/repositories/api_warga}"
PWA_REPO="${PWA_REPO:-$HOME/repositories/smardesa_warga}"
API_ROOT="${API_ROOT:-$HOME/domains/api-warga-smartdesa.mediaverse.co.id/public_html}"
PWA_ROOT="${PWA_ROOT:-$HOME/domains/warga-smartdesa.mediaverse.co.id/public_html}"
PRIVATE_ROOT="${PRIVATE_ROOT:-$HOME/smartdesa-private}"
API_HEALTH_URL="${API_HEALTH_URL:-https://api-warga-smartdesa.mediaverse.co.id/v1/health}"

require_command() {
    command -v "$1" >/dev/null 2>&1 || {
        printf 'ERROR: perintah %s tidak tersedia.\n' "$1" >&2
        exit 1
    }
}

for command_name in git php composer rsync mysql mysqldump curl; do
    require_command "$command_name"
done

for repo_path in "$API_REPO" "$PWA_REPO"; do
    if [[ ! -d "$repo_path/.git" ]]; then
        printf 'ERROR: repository tidak ditemukan: %s\n' "$repo_path" >&2
        exit 1
    fi
done

if [[ ! -f "$API_ROOT/.env" || ! -f "$PWA_ROOT/.env" ]]; then
    printf 'ERROR: .env produksi API atau PWA tidak ditemukan.\n' >&2
    exit 1
fi

printf 'Mengambil source terbaru...\n'
git -C "$API_REPO" pull --ff-only origin main
git -C "$PWA_REPO" pull --ff-only origin main

printf 'Memasang dependensi PWA...\n'
composer --working-dir="$PWA_REPO" install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction

mkdir -p "$PRIVATE_ROOT/backups" "$PRIVATE_ROOT/warga" "$API_ROOT" "$PWA_ROOT"
chmod 750 "$PRIVATE_ROOT" "$PRIVATE_ROOT/backups" "$PRIVATE_ROOT/warga"

mysql_defaults="$(mktemp "${TMPDIR:-/tmp}/smartdesa-warga-mysql.XXXXXX")"
database_name_file="$mysql_defaults.database"
cleanup() {
    rm -f "$mysql_defaults" "$database_name_file"
}
trap cleanup EXIT
chmod 600 "$mysql_defaults"

php -r '
$path = $argv[1];
$output = $argv[2];
$env = [];
foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#" || strpos($line, "=") === false) continue;
    [$key, $value] = explode("=", $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && (($value[0] === "\"" && substr($value, -1) === "\"") || ($value[0] === chr(39) && substr($value, -1) === chr(39)))) {
        $value = substr($value, 1, -1);
    }
    $env[trim($key)] = $value;
}
foreach (["DB_HOST", "DB_USER", "DB_PASS", "DB_NAME"] as $required) {
    if (!isset($env[$required]) || ($required !== "DB_PASS" && trim($env[$required]) === "")) {
        fwrite(STDERR, "ERROR: {$required} belum terisi pada .env API.\n");
        exit(1);
    }
}
$quote = static function ($value) {
    return "\"" . str_replace(["\\", "\"", "\r", "\n"], ["\\\\", "\\\"", "", ""], $value) . "\"";
};
$contents = "[client]\n"
    . "host=" . $quote($env["DB_HOST"]) . "\n"
    . "port=" . (int) ($env["DB_PORT"] ?? 3306) . "\n"
    . "user=" . $quote($env["DB_USER"]) . "\n"
    . "password=" . $quote($env["DB_PASS"]) . "\n"
    . "default-character-set=utf8mb4\n";
if (file_put_contents($output, $contents, LOCK_EX) === false || !chmod($output, 0600)) exit(1);
echo $env["DB_NAME"];
' "$API_ROOT/.env" "$mysql_defaults" >"$database_name_file"

database_name="$(cat "$database_name_file")"
rm -f "$database_name_file"
if [[ ! "$database_name" =~ ^[A-Za-z0-9_]+$ ]]; then
    printf 'ERROR: nama database tidak valid.\n' >&2
    exit 1
fi

backup_file="$PRIVATE_ROOT/backups/warga-$(date +%Y%m%d-%H%M%S).sql"
printf 'Mencadangkan database ke %s...\n' "$backup_file"
mysqldump --defaults-extra-file="$mysql_defaults" \
    --single-transaction --skip-lock-tables "$database_name" >"$backup_file"
chmod 600 "$backup_file"

printf 'Menjalankan migrasi database 006 sampai 010...\n'
for migration in \
    006_service_catalog \
    007_resident_directory \
    008_unique_citizen_source \
    009_official_documents \
    010_sync_aggregate_keys
do
    migration_file="$API_REPO/database/migrations/$migration.sql"
    if [[ ! -f "$migration_file" ]]; then
        printf 'ERROR: migrasi tidak ditemukan: %s\n' "$migration_file" >&2
        exit 1
    fi
    mysql --defaults-extra-file="$mysql_defaults" "$database_name" <"$migration_file"
done

deploy_api() {
    rsync -a --delete \
        --exclude='.git/' \
        --exclude='.env' \
        --exclude='application/cache/*' \
        --exclude='application/logs/*' \
        --exclude='application/sessions/*' \
        --exclude='storage/*' \
        --exclude='scripts/' \
        --exclude='tools/' \
        "$API_REPO/" "$API_ROOT/"
}

deploy_pwa() {
    rsync -a --delete \
        --exclude='.git/' \
        --exclude='.env' \
        --exclude='application/cache/*' \
        --exclude='application/logs/*' \
        --exclude='application/sessions/*' \
        --exclude='storage/*' \
        --exclude='uploads/requests/*' \
        --exclude='tools/' \
        "$PWA_REPO/" "$PWA_ROOT/"
}

printf 'Menyalin API dan PWA ke document root...\n'
deploy_api
deploy_pwa

chmod 600 "$API_ROOT/.env" "$PWA_ROOT/.env"

printf 'Memeriksa API...\n'
health_response="$(curl --fail --silent --show-error --max-time 30 "$API_HEALTH_URL")"
php -r '
$data = json_decode($argv[1], true);
if (!is_array($data) || empty($data["success"]) || ($data["database"] ?? "") !== "ready") {
    fwrite(STDERR, "ERROR: respons health API tidak valid.\n");
    exit(1);
}
echo "API dan database siap.\n";
' "$health_response"

printf 'Deployment selesai. Backup: %s\n' "$backup_file"
