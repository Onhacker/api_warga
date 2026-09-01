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

`/v1/installations/auto-enroll` adalah endpoint bootstrap installer universal. Endpoint
ini memakai header HMAC terpisah berikut dan hanya aktif bila sengaja dikonfigurasi pada
server pusat:

```text
X-SmartDesa-Auto-Key
X-SmartDesa-Auto-Timestamp
X-SmartDesa-Auto-Nonce
X-SmartDesa-Auto-Signature
```

Pesan kanoniknya sama dengan signature sinkronisasi. API membaca `village_code` dari
payload yang dikirim backend SmartDesa lokal, mengikat satu instalasi aktif ke satu
`device_id`, dan menolak perpindahan perangkat tanpa tindakan pengelola pusat. Bootstrap
tidak pernah dikirim ke browser.

## Deployment

1. Buat database `smartdesa_warga`, impor `database/schema.sql`, lalu `database/seed.sql` dari proyek PWA.
2. Jika database lama, impor `database/migrations/001_sync_auth.sql`, `database/migrations/002_set_araboda_official.sql`, `database/migrations/003_seed_jayawijaya_villages.sql`, `database/migrations/004_installation_enrollment.sql`, lalu `database/migrations/005_auto_enrollment.sql` sesuai urutan.
3. Upload isi folder ini ke document root `api-warga-smartdesa.mediaverse.co.id`.
4. Salin `.env.example` menjadi `.env`, isi `APP_KEY`, database, dan `WARGA_ALLOWED_ORIGIN`.
5. Buat folder `PRIVATE_STORAGE_PATH` di luar `public_html`, pastikan dapat dibaca PHP,
   dan gunakan path yang sama dengan PWA warga bila keduanya berbagi storage.
6. Pastikan `API_DEMO_MODE=0` sebelum dipakai desa.
7. Pastikan data wilayah telah diimpor. Seed dan migrasi `003` memuat 332 kampung/kelurahan pada 40 distrik di Kabupaten Jayawijaya.
8. Buat satu baris `village_installations` per instalasi desa. Jika tool dijalankan dari repository, arahkan ke `.env` deployment secara eksplisit. Contoh Hostinger: `API_ENV=/home/USER/domains/api-warga-smartdesa.mediaverse.co.id/public_html/.env`. Untuk seluruh desa aktif, jalankan `php tools/provision_installations.php --all --env="$API_ENV"` sebagai preview, kemudian ulangi dengan `--write` dan `--output` privat di luar `public_html`. File kredensial hanya untuk pemulihan oleh pengelola pusat dan tidak dibagikan kepada desa.
9. Konfigurasikan bootstrap otomatis satu kali pada API pusat dan buat snippet privat untuk workstation builder:

   ```bash
   php tools/configure_auto_enrollment.php \
     --env="$API_ENV" \
     --builder-output="$HOME/smartdesa-private/warga-builder.env" \
     --write
   ```

   Perintah tidak menampilkan secret. Pindahkan file `warga-builder.env` secara aman ke workstation builder, lalu impor nilainya ke `.env.build` SmartDesa. Jangan commit, upload ke `public_html`, atau membagikannya kepada desa.
10. Bangun satu installer SmartDesa universal. Saat Administrator membuka modul Permohonan Warga dan internet tersedia, backend lokal membaca kode kampung dari Identitas Kampung, melakukan handshake HMAC, menyimpan kredensial khusus kampung, lalu menghapus bootstrap dari `.env` lokal. Tidak ada kode atau pengaturan API yang perlu diketik oleh desa.
11. `tools/issue_enrollment_codes.php` dan endpoint `/v1/installations/enroll` dipertahankan hanya sebagai jalur pemulihan instalasi lama. Jangan gunakan alur pembagian kode untuk pemasangan normal.
12. Pantau cakupan dan aktivitas tanpa membuka secret dengan `php tools/report_installations.php --env="$API_ENV" --format=text` atau `--format=csv`. Status `last_seen_at` diperbarui setiap permintaan bertanda tangan dan `last_sync_at` setelah pull/push/ack berhasil.
13. Uji `GET /v1/health`, koneksi otomatis satu desa pilot, pemutusan bootstrap lokal, lalu pull/push sebelum merilis installer ke seluruh desa.

### Model multi-desa

Satu API dan satu database pusat dapat melayani seluruh desa aktif. `village_tenants` menyimpan daftar wilayah yang dapat dipilih warga; `village_installations` menyimpan kredensial unik untuk setiap laptop desa. Setiap permohonan dan pesan sinkronisasi memiliki `village_id`, sehingga laptop Kampung A tidak dapat menarik antrean Kampung B. Tidak perlu membuat satu subdomain atau database untuk setiap desa.

Rekomendasi rollout:

1. Uji satu instalasi pilot dan pastikan identitas kampung lokal menghasilkan tenant yang benar serta perangkat terikat hanya satu kali.
2. Tambahkan desa secara bertahap; satu laptop utama per desa adalah kebijakan awal yang paling sederhana.
3. Pantau `report_installations.php` untuk melihat desa yang belum aktif, versi aplikasi, waktu terakhir online, dan antrean.
4. Setelah prosedur stabil, bulk provisioning dapat dipakai untuk desa yang siap. Jangan memakai satu `installation_code` atau secret untuk beberapa desa.

Mode demo hanya aktif jika sengaja diatur melalui `.env` untuk pengujian lokal. Jangan mengaktifkannya pada server produksi.

Panduan pemasangan bersama PWA tersedia pada `../smartdesa-warga/DEPLOY_HOSTINGER.md`.
