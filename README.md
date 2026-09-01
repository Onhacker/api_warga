# SmartDesa Warga API

Backend sinkronisasi untuk SmartDesa lokal dan PWA warga. Proyek ini dipasang terpisah pada:

```text
https://api-warga-smartdesa.mediaverse.co.id/
```

PWA dipasang pada `https://warga-smartdesa.mediaverse.co.id/`. Keduanya boleh memakai database pusat yang sama, tetapi file `.env`, document root, dan kredensial deployment harus terpisah.

## Endpoint

```text
GET  /v1/health
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

## Deployment

1. Buat database `smartdesa_warga`, impor `database/schema.sql`, lalu `database/seed.sql` dari proyek PWA.
2. Jika database lama, impor `database/migrations/001_sync_auth.sql`, `database/migrations/002_set_araboda_official.sql`, lalu `database/migrations/003_seed_jayawijaya_villages.sql` sesuai urutan.
3. Upload isi folder ini ke document root `api-warga-smartdesa.mediaverse.co.id`.
4. Salin `.env.example` menjadi `.env`, isi `APP_KEY`, database, dan `WARGA_ALLOWED_ORIGIN`.
5. Buat folder `PRIVATE_STORAGE_PATH` di luar `public_html`, pastikan dapat dibaca PHP,
   dan gunakan path yang sama dengan PWA warga bila keduanya berbagi storage.
6. Pastikan `API_DEMO_MODE=0` sebelum dipakai desa.
7. Pastikan data wilayah telah diimpor. Seed dan migrasi `003` memuat 332 kampung/kelurahan pada 40 distrik di Kabupaten Jayawijaya.
8. Buat satu baris `village_installations` per instalasi desa. Untuk satu desa, gunakan `php tools/provision_installation.php --village=KODE-DESA --write`. Untuk rollout bertahap atau seluruh desa aktif, gunakan `php tools/provision_installations.php --all` terlebih dahulu sebagai preview. Perintah bulk tidak membuat secret sebelum `--write` diberikan.
9. Saat bulk provisioning benar-benar dimulai, gunakan output privat, misalnya `php tools/provision_installations.php --all --write --output=/home/USER/smartdesa-private/installation-credentials.json`. File tersebut permission `0600` dan wajib berada di luar `public_html`; distribusikan setiap kredensial hanya ke laptop desa yang sesuai, lalu hapus atau simpan di vault setelah konfigurasi selesai.
10. Pantau cakupan dan aktivitas tanpa membuka secret dengan `php tools/report_installations.php --format=text` atau `--format=csv`. Status `last_seen_at` diperbarui setiap permintaan bertanda tangan dan `last_sync_at` setelah pull/push/ack berhasil.
11. Uji `GET /v1/health`, lalu uji signature dari klien SmartDesa.

### Model multi-desa

Satu API dan satu database pusat dapat melayani seluruh desa aktif. `village_tenants` menyimpan daftar wilayah yang dapat dipilih warga; `village_installations` menyimpan kredensial unik untuk setiap laptop desa. Setiap permohonan dan pesan sinkronisasi memiliki `village_id`, sehingga laptop Kampung A tidak dapat menarik antrean Kampung B. Tidak perlu membuat satu subdomain atau database untuk setiap desa.

Rekomendasi rollout:

1. Uji satu instalasi pilot dan pastikan kode instalasi cocok dengan desa pada laptop.
2. Tambahkan desa secara bertahap; satu laptop utama per desa adalah kebijakan awal yang paling sederhana.
3. Pantau `report_installations.php` untuk melihat desa yang belum aktif, versi aplikasi, waktu terakhir online, dan antrean.
4. Setelah prosedur stabil, bulk provisioning dapat dipakai untuk desa yang siap. Jangan memakai satu `installation_code` atau secret untuk beberapa desa.

Mode demo hanya aktif jika sengaja diatur melalui `.env` untuk pengujian lokal. Jangan mengaktifkannya pada server produksi.

Panduan pemasangan bersama PWA tersedia pada `../smartdesa-warga/DEPLOY_HOSTINGER.md`.
