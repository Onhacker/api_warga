# Rollout Multi-Desa SmartDesa Warga

## Gambaran

```text
PWA warga (online)
        |
        v
API pusat + database pusat
        |
        +-- antrean village_id A -- laptop desa A
        +-- antrean village_id B -- laptop desa B
        +-- antrean village_id C -- laptop desa C
```

Laptop desa tidak perlu menerima koneksi masuk dari internet. Laptop hanya
membuka koneksi HTTPS keluar saat melakukan sinkronisasi. Jika laptop sedang
offline, permohonan tetap berada di antrean pusat; perubahan yang dibuat
petugas tetap berada di outbox lokal sampai koneksi tersedia.

## Satu desa, satu instalasi utama

Untuk tahap awal, buat satu `village_installations` aktif untuk satu laptop
utama di setiap desa. Credential terdiri dari:

- `installation_code`: identitas instalasi, bukan key global;
- `secret`: rahasia HMAC, disimpan di konfigurasi server lokal dan tidak
  dikirim ke browser.

API mencocokkan credential dengan `village_id`. Karena itu credential Araboda
tidak boleh dipasang di laptop desa lain. Beberapa laptop untuk satu desa
sebaiknya baru diaktifkan setelah aturan konflik dan pembagian antrean diuji.

## Prosedur installer universal

1. Pastikan baris desa sudah aktif di `village_tenants`.
2. Buat credential satu desa, atau buat batch untuk desa yang siap:

   ```bash
   API_ENV=/home/USER/domains/api-warga-smartdesa.mediaverse.co.id/public_html/.env
   php tools/provision_installations.php --village=95.01.03.2003 --env="$API_ENV"
   php tools/provision_installations.php --village=95.01.03.2003 --write \
     --env="$API_ENV" \
     --output=/home/USER/smartdesa-private/araboda-credential.json
   ```

3. Provisioning baru sudah membuat `enrollment_code` bersama kredensial sinkronisasi.
   Ambil hanya kode milik desa dari file output privat. Untuk instalasi lama yang
   belum memiliki kode, kode kedaluwarsa, atau penerbitan ulang, gunakan:

   ```bash
   php tools/issue_enrollment_codes.php --all --env="$API_ENV"
   php tools/issue_enrollment_codes.php --all --write --days=90 \
     --env="$API_ENV" \
     --output=/home/USER/smartdesa-private/enrollment-codes.json
   ```

   Tanpa `--force`, kode yang masih siap digunakan tidak ditimpa. Tambahkan
   `--force` hanya bila kode lama memang harus dibatalkan dan diganti.

4. Gunakan satu installer SmartDesa yang sama untuk seluruh desa. Berikan hanya
   `enrollment_code` desa yang bersangkutan. Operator memilih distrik dan kampung,
   lalu aplikasi menyimpan `installation_code` serta secret secara otomatis.
5. Jalankan tes koneksi dan sinkronisasi dari laptop desa.
6. Verifikasi bahwa `last_seen_at` dan `last_sync_at` berubah pada laporan
   pusat.

Untuk semua desa yang belum diprovision:

```bash
php tools/provision_installations.php --all --env="$API_ENV"
php tools/provision_installations.php --all --write \
  --env="$API_ENV" \
  --output=/home/USER/smartdesa-private/installation-credentials.json
```

File provisioning berisi secret mentah sekaligus `enrollment_code` dan dibuat
dengan permission `0600`. File tersebut hanya untuk pengelola pusat. Bagikan
hanya satu nilai `enrollment_code` kepada desa yang sesuai. File hasil penerbitan
ulang tidak berisi secret, tetapi tetap privat: jangan menyimpannya di
`public_html`, repository, atau membagikan seluruh file kepada satu desa.

## Monitoring

```bash
php tools/report_installations.php --env="$API_ENV" --format=text
php tools/report_installations.php --env="$API_ENV" --format=csv > /home/USER/smartdesa-private/installation-report.csv
```

Kolom `AKTIVASI` menunjukkan apakah kode masih siap, sudah dipakai, kedaluwarsa,
atau belum tersedia. Kolom `IN` menunjukkan antrean dari warga menuju laptop
desa, `OUT` menunjukkan perubahan dari laptop menuju API, dan `FAIL` menunjukkan
pesan yang gagal.

## Alur permohonan

1. Warga memilih distrik dan kampung pada PWA, lalu mengirim permohonan.
2. API menyimpan permohonan dengan `village_id` dan membuat pesan
   `cloud_to_local`.
3. Laptop desa melakukan `pull`; API hanya mengembalikan pesan untuk
   `village_id` credential tersebut.
4. Sekdes memverifikasi. Kepala desa menyetujui sesuai alur peran.
5. Keputusan masuk outbox lokal dan dikirim melalui `push` saat online.
6. API memperbarui permohonan dan warga melihat status terbaru pada PWA.

## Kebijakan operasional

- Gunakan satu API/database pusat; tidak perlu subdomain/database per desa.
- Jangan memakai secret yang sama antar desa.
- Nonaktifkan baris instalasi yang hilang atau dicurigai tanpa mematikan desa
  lain.
- Backup database pusat dan penyimpanan berkas secara terpisah.
- Untuk skala besar, jadwalkan polling ringan dengan jeda acak dan backoff;
  jangan membuat semua laptop melakukan request pada detik yang sama.
