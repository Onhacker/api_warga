# Database API Warga

Gunakan schema dan seed dari `/htdocs/smartdesa-warga/database`. File yang disalin di folder ini hanya untuk memudahkan paket API mandiri.

Untuk database yang sudah ada, jalankan `migrations/001_sync_auth.sql` sebelum `API_DEMO_MODE=0`. API produksi membutuhkan kolom `village_installations.sync_secret_encrypted` dan tabel `api_request_nonces`. Jika tenant awal masih memakai kode contoh lama, jalankan `migrations/002_set_araboda_official.sql` sekali. Setelah itu jalankan `migrations/003_seed_jayawijaya_villages.sql` untuk memasukkan seluruh 332 kampung/kelurahan pada 40 distrik di Kabupaten Jayawijaya, `migrations/004_installation_enrollment.sql`, lalu `migrations/005_auto_enrollment.sql` untuk koneksi otomatis installer universal.

Setiap baris aktif pada `village_tenants` mewakili satu kampung/kelurahan yang dapat dipilih warga. Seed instalasi baru sudah memuat seluruh Kabupaten Jayawijaya. Jalankan provisioning hanya untuk setiap instalasi desa yang akan disinkronkan.

Untuk database yang sudah berjalan, jalankan `migrations/006_service_catalog.sql` setelah migrasi sebelumnya. Migrasi ini menambahkan katalog Master Surat per desa, versi formulir, dan metadata lampiran yang dikirim melalui sinkronisasi.

Setelah itu jalankan `migrations/007_resident_directory.sql`. Migrasi ini menambahkan direktori penduduk per kampung untuk verifikasi pendaftaran warga. NIK dan No. KK tidak disimpan mentah di server; API hanya menyimpan hash identitas dan nama snapshot.

Jika migration `007` sudah pernah dijalankan sebelum pengaman akun unik ditambahkan, jalankan `migrations/008_unique_citizen_source.sql`. Migrasi ini memastikan satu penduduk lokal hanya dapat memiliki satu akun PWA pada kampung/desanya. Setelah itu jalankan `migrations/009_official_documents.sql` untuk metadata PDF resmi dari SmartDesa lokal.

Jalankan `migrations/010_sync_aggregate_keys.sql` setelahnya. UUID permohonan tetap berlaku, tetapi kunci katalog dan snapshot penduduk memerlukan `sync_messages.aggregate_id` sepanjang 120 karakter. Migrasi ini aman dijalankan kembali. Lanjutkan dengan `011_official_html.sql`, `012_citizen_identity_details.sql`, `013_community_services.sql`, `014_staff_credentials.sql`, dan `015_sync_integrity.sql` agar alur HTML, akun petugas, nomor versi, fingerprint idempotensi, dan staging snapshot tersedia.

Jalankan `migrations/018_global_nik_uniqueness.sql` setelah data ganda lama diselesaikan. Migrasi ini menambahkan indeks unik global pada direktori penduduk dan profil akun; sinkronisasi dari dua kampung tidak dapat memakai NIK yang sama. Migrasi sengaja gagal bila database masih berisi hash NIK ganda, sehingga tidak ada data yang dihapus otomatis. Untuk reset data uji secara terkontrol, jalankan pemeriksaan dengan `php tools/reset_warga_test_data.php --env=/path/.env --report-only`, buat backup, lalu ulangi dengan `--confirm=RESET-WARGA-TEST-DATA`. Data Pasar Digital dipertahankan secara default; opsi `--include-marketplace` harus ditambahkan secara sadar bila data itu juga memang akan dihapus.

Untuk dashboard monitoring pada server SmartDesa pusat, jalankan `migrations/019_monitoring_auth.sql` pada database API. Isi `WARGA_MONITOR_API_KEY` dan `WARGA_MONITOR_API_SECRET` pada `.env` API (key 16-128 karakter dengan huruf/angka/tanda `.`, `_`, atau `-`; secret minimal 32 karakter), lalu isi kredensial yang sama pada pengaturan `API Monitoring Layanan Warga` di SmartDesa pusat. Secret monitoring berbeda dari secret instalasi desa dan tidak boleh dimasukkan ke PWA atau installer. Endpoint ringkasan hanya mengembalikan metrik agregat per kampung; NIK, No. KK, password, isi permohonan, dan dokumen tidak dikirim ke dashboard.

Setelah itu jalankan `migrations/020_reinstall_enrollment.sql`. Migrasi ini menyimpan fingerprint perangkat yang sudah dibuktikan oleh grant aktivasi, sehingga instalasi ulang pada laptop yang sama dapat terhubung kembali tanpa membuka akses ke perangkat lain.

Untuk fitur **Rebind / Pindah Kampung** pada dashboard Super Admin, jalankan `migrations/021_installation_rebind_audit.sql`. Operasi ini merotasi kredensial instalasi sumber dan tujuan, menyimpan jejak audit tanpa secret asli, serta tidak menghapus atau memindahkan data penduduk, surat, akun, permohonan, maupun dokumen.

Migration `004` dan endpoint bootstrap lama hanya dipertahankan sebagai jalur pemulihan terkontrol. Alur utama memakai grant aktivasi sekali pakai: aplikasi lokal membaca kode kampung dari identitas instalasi, meminta grant singkat dari server aktivasi, lalu API menerbitkan kredensial khusus instalasi. Secret penandatangan grant hanya berada di server aktivasi dan API; jangan menyimpannya di repository, `.env.build`, installer, atau membagikannya kepada desa.
