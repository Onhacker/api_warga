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
2. Jika database lama, impor `database/migrations/001_sync_auth.sql`.
3. Upload isi folder ini ke document root `api-warga-smartdesa.mediaverse.co.id`.
4. Salin `.env.example` menjadi `.env`, isi `APP_KEY`, database, dan `WARGA_ALLOWED_ORIGIN`.
5. Buat folder `PRIVATE_STORAGE_PATH` di luar `public_html`, pastikan dapat dibaca PHP,
   dan gunakan path yang sama dengan PWA warga bila keduanya berbagi storage.
6. Pastikan `API_DEMO_MODE=0` sebelum dipakai desa.
7. Pastikan setiap kampung/desa yang akan dilayani sudah memiliki baris aktif pada `village_tenants`. Tenant awal Araboda menggunakan kode resmi `95.01.03.2003`.
8. Buat satu baris `village_installations` per instalasi desa. Untuk preview kredensial jalankan `php tools/provision_installation.php --village=KODE-DESA`; tambahkan `--write` setelah database siap. Satu perintah hanya membuat kredensial untuk satu desa, bukan membatasi API hanya untuk satu desa. Simpan `installation_code` dan `secret` di konfigurasi SmartDesa lokal, karena secret hanya ditampilkan saat provisioning.
9. Uji `GET /v1/health`, lalu uji signature dari klien SmartDesa.

Mode demo hanya aktif jika sengaja diatur melalui `.env` untuk pengujian lokal. Jangan mengaktifkannya pada server produksi.

Panduan pemasangan bersama PWA tersedia pada `../smartdesa-warga/DEPLOY_HOSTINGER.md`.
