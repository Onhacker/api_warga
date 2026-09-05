# SmartDesa Warga API

Backend sinkronisasi untuk SmartDesa lokal dan PWA warga. Proyek ini dipasang terpisah pada:

```text
https://api-warga-smartdesa.mediaverse.co.id/
```

PWA dipasang pada `https://warga-smartdesa.mediaverse.co.id/`. Keduanya boleh memakai database pusat yang sama, tetapi file `.env`, document root, dan kredensial deployment harus terpisah.

## Endpoint

```text
GET  /v1/health
GET  /v1/installations/villages
POST /v1/installations/enroll
POST /v1/installations/auto-enroll
POST /v1/sync/pull
POST /v1/sync/push
POST /v1/sync/ack
GET  /v1/documents/{document-id}
POST /v1/requests/{request-id}/official-document
```

`/v1/health` tidak membutuhkan kredensial. Endpoint sinkronisasi dan dokumen memakai header berikut:

```text
X-SmartDesa-Installation
X-SmartDesa-Timestamp
X-SmartDesa-Nonce
X-SmartDesa-Signature
```

Signature adalah HMAC-SHA256 hex dengan pesan kanonik:

```text
timestamp + "\n" + nonce + "\n" + METHOD + "\n" + PATH + "\n" + RAW_JSON_BODY
```

Timestamp hanya berlaku selama `API_SIGNATURE_TTL` detik. Nonce tidak boleh digunakan ulang. Semua komunikasi produksi wajib HTTPS.
Endpoint dokumen juga wajib memakai header HMAC yang sama. ID dokumen harus berasal dari
metadata permohonan yang diterima SmartDesa; API memeriksa desa pemilik sebelum membaca
berkas dari `PRIVATE_STORAGE_PATH`.

Endpoint `official-document` menerima PDF resmi setelah permohonan berstatus `approved`.
Isi PDF, hash SHA-256, desa pemilik, dan identitas permohonan diverifikasi sebelum status
berubah menjadi `issued`. Pengiriman ulang PDF yang sama bersifat idempotent; PDF berbeda
untuk permohonan yang sudah terbit ditolak.

`/v1/installations/auto-enroll` adalah endpoint pengaitan installer universal. Installer
baru membawa grant singkat yang diterbitkan server aktivasi setelah memeriksa aktivasi
lokal, kode kampung, dan perangkat. Grant ditandatangani dengan
`WARGA_ENROLL_GRANT_SECRET`, hanya berlaku lima menit, dan hanya dapat dipakai sekali.
Secret tersebut hanya ada di server aktivasi serta API Warga; jangan masukkan ke installer.

Untuk pemulihan installer lama, endpoint masih dapat memakai header bootstrap HMAC berikut,
tetapi hanya jika `WARGA_AUTO_ENROLL_LEGACY_ENABLED=1` dinyalakan secara eksplisit:

```text
X-SmartDesa-Auto-Key
X-SmartDesa-Auto-Timestamp
X-SmartDesa-Auto-Nonce
X-SmartDesa-Auto-Signature
```

Pesan kanoniknya sama dengan signature sinkronisasi. API membaca `village_code` dari
payload yang dikirim backend SmartDesa lokal, mengikat satu instalasi aktif ke satu
`device_id`, dan menolak perpindahan perangkat tanpa tindakan pengelola pusat. Bootstrap
lama tidak pernah dikirim ke browser dan sebaiknya dinonaktifkan setelah seluruh instalasi
berpindah ke grant.

## Deployment

Untuk deployment rutin di Hostinger setelah instalasi awal, jalankan satu perintah berikut
dari repository API. Skrip mempertahankan `.env`, upload, log, dan data runtime; membuat
backup database; menjalankan migrasi `006` sampai `015`; lalu memeriksa health API.

```bash
cd "$HOME/repositories/api_warga"
bash scripts/deploy-hostinger.sh
```

1. Buat database `smartdesa_warga`, impor `database/schema.sql`, lalu `database/seed.sql` dari proyek PWA.
2. Jika database lama, impor semua berkas `database/migrations/001_*.sql` sampai
   `database/migrations/015_*.sql` sesuai urutan. Migrasi `009` menambahkan metadata PDF resmi,
   `010` memperpanjang `sync_messages.aggregate_id`, dan `015` menambahkan fingerprint pesan,
   versi direktori, serta staging snapshot atomik.
3. Upload isi folder ini ke document root `api-warga-smartdesa.mediaverse.co.id`.
4. Salin `.env.example` menjadi `.env`, isi `APP_KEY`, database, dan `WARGA_ALLOWED_ORIGIN`.
5. Buat folder `PRIVATE_STORAGE_PATH` di luar `public_html`, pastikan dapat ditulis PHP,
   dan gunakan path absolut yang sama pada API serta PWA. PDF resmi ditulis API dan dibaca
   PWA langsung dari folder privat ini.
6. Pastikan `API_DEMO_MODE=0` sebelum dipakai desa.
7. Pastikan data wilayah telah diimpor. Seed dan migrasi `003` memuat 332 kampung/kelurahan pada 40 distrik di Kabupaten Jayawijaya.
8. Buat satu baris `village_installations` per instalasi desa. Jika tool dijalankan dari repository, arahkan ke `.env` deployment secara eksplisit. Contoh Hostinger: `API_ENV=/home/USER/domains/api-warga-smartdesa.mediaverse.co.id/public_html/.env`. Untuk seluruh desa aktif, jalankan `php tools/provision_installations.php --all --env="$API_ENV"` sebagai preview, kemudian ulangi dengan `--write` dan `--output` privat di luar `public_html`. File kredensial hanya untuk pemulihan oleh pengelola pusat dan tidak dibagikan kepada desa.
9. Konfigurasikan secret grant yang sama pada server aktivasi dan API Warga. Secret ini
   tidak boleh masuk repository, `.env` builder, atau installer desa. Pastikan client
   lokal memiliki setting `activation_online` yang aktif agar dapat meminta grant.

   Builder cukup memakai URL API Warga dan memeriksa kredensial Aktivasi Online; ia tidak
   lagi membutuhkan `SMARTDESA_WARGA_AUTO_ENROLL_KEY/SECRET`.

   Untuk menyiapkan mode grant dan menonaktifkan bootstrap lama, jalankan:

   ```bash
   php tools/configure_auto_enrollment.php \
     --env="$API_ENV" \
     --builder-output="$HOME/smartdesa-private/warga-builder.env" \
     --write
   ```

   File tersebut hanya berisi URL dan mode builder, tidak memuat secret grant. Simpan di luar
   `public_html` dan jangan masukkan ke installer sebagai kredensial bersama.
10. Bangun satu installer SmartDesa universal. Saat Administrator membuka modul Permohonan Warga
    atau worker terjadwal berjalan dan internet tersedia, backend lokal membaca kode kampung
    dari Identitas Kampung, meminta grant dari server aktivasi, menyimpan kredensial khusus
    kampung, lalu mematikan mode enrollment sementara. Tidak ada kode atau pengaturan API yang
    perlu diketik oleh desa.
11. `tools/issue_enrollment_codes.php` dan endpoint `/v1/installations/enroll` dipertahankan hanya sebagai jalur pemulihan instalasi lama. Jangan gunakan alur pembagian kode untuk pemasangan normal.
12. Pantau cakupan dan aktivitas tanpa membuka secret dengan `php tools/report_installations.php --env="$API_ENV" --format=text` atau `--format=csv`. Status `last_seen_at` diperbarui setiap permintaan bertanda tangan dan `last_sync_at` setelah pull/push/ack berhasil.
13. Uji `GET /v1/health`, koneksi otomatis satu desa pilot, pemutusan bootstrap lokal,
    katalog layanan, verifikasi penduduk, pull/push, serta penerbitan dan unduh PDF sebelum
    merilis installer ke seluruh desa.

Tes regresi penerbitan dapat dijalankan pada mesin pengembangan yang memiliki MariaDB:

```bash
SMARTDESA_TEST_DB_SOCKET=/path/to/mysql.sock \
SMARTDESA_TEST_DB_USER=test-user \
SMARTDESA_TEST_DB_PASS=test-password \
php tools/tests/official_documents.php
```

Tes selalu membuat database bernama acak dan menghapusnya kembali. Jangan arahkan user tes
ke kredensial produksi yang memiliki hak di luar kebutuhan pengujian.

`tools/tests/workflow_integration.php` menguji migrasi berulang, katalog lintas desa,
direktori penduduk, pembuatan permohonan melalui model PWA, dan alur status. Source PWA
dibaca dari folder sejajar `smartdesa-warga`; atur `SMARTDESA_TEST_PWA_PATH` jika berbeda.
Gunakan variabel koneksi database tes yang sama seperti perintah di atas. Autentikasi HTTP
dan unggah berkas nyata tetap harus diuji pada lingkungan pilot.

### Model multi-desa

Satu API dan satu database pusat dapat melayani seluruh desa aktif. `village_tenants` menyimpan daftar wilayah yang dapat dipilih warga; `village_installations` menyimpan kredensial unik untuk setiap laptop desa. Setiap permohonan dan pesan sinkronisasi memiliki `village_id`, sehingga laptop Kampung A tidak dapat menarik antrean Kampung B. Tidak perlu membuat satu subdomain atau database untuk setiap desa.

Rekomendasi rollout:

1. Uji satu instalasi pilot dan pastikan identitas kampung lokal menghasilkan tenant yang benar serta perangkat terikat hanya satu kali.
2. Tambahkan desa secara bertahap; satu laptop utama per desa adalah kebijakan awal yang paling sederhana.
3. Pantau `report_installations.php` untuk melihat desa yang belum aktif, versi aplikasi, waktu terakhir online, dan antrean.
4. Setelah prosedur stabil, bulk provisioning dapat dipakai untuk desa yang siap. Jangan memakai satu `installation_code` atau secret untuk beberapa desa.

Mode demo hanya aktif jika sengaja diatur melalui `.env` untuk pengujian lokal. Jangan mengaktifkannya pada server produksi.

Panduan pemasangan bersama PWA tersedia pada `../smartdesa-warga/DEPLOY_HOSTINGER.md`.
