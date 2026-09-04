# Database API Warga

Gunakan schema dan seed dari `/htdocs/smartdesa-warga/database`. File yang disalin di folder ini hanya untuk memudahkan paket API mandiri.

Untuk database yang sudah ada, jalankan `migrations/001_sync_auth.sql` sebelum `API_DEMO_MODE=0`. API produksi membutuhkan kolom `village_installations.sync_secret_encrypted` dan tabel `api_request_nonces`. Jika tenant awal masih memakai kode contoh lama, jalankan `migrations/002_set_araboda_official.sql` sekali. Setelah itu jalankan `migrations/003_seed_jayawijaya_villages.sql` untuk memasukkan seluruh 332 kampung/kelurahan pada 40 distrik di Kabupaten Jayawijaya, `migrations/004_installation_enrollment.sql`, lalu `migrations/005_auto_enrollment.sql` untuk koneksi otomatis installer universal.

Setiap baris aktif pada `village_tenants` mewakili satu kampung/kelurahan yang dapat dipilih warga. Seed instalasi baru sudah memuat seluruh Kabupaten Jayawijaya. Jalankan provisioning hanya untuk setiap instalasi desa yang akan disinkronkan.

Untuk database yang sudah berjalan, jalankan `migrations/006_service_catalog.sql` setelah migrasi sebelumnya. Migrasi ini menambahkan katalog Master Surat per desa, versi formulir, dan metadata lampiran yang dikirim melalui sinkronisasi.

Setelah itu jalankan `migrations/007_resident_directory.sql`. Migrasi ini menambahkan direktori penduduk per kampung untuk verifikasi pendaftaran warga. NIK dan No. KK tidak disimpan mentah di server; API hanya menyimpan hash identitas dan nama snapshot.

Jika migration `007` sudah pernah dijalankan sebelum pengaman akun unik ditambahkan, jalankan `migrations/008_unique_citizen_source.sql`. Migrasi ini memastikan satu penduduk lokal hanya dapat memiliki satu akun PWA pada kampung/desanya. Setelah itu jalankan `migrations/009_official_documents.sql` untuk metadata PDF resmi dari SmartDesa lokal.

Jalankan `migrations/010_sync_aggregate_keys.sql` setelahnya. UUID permohonan tetap berlaku, tetapi kunci katalog dan snapshot penduduk memerlukan `sync_messages.aggregate_id` sepanjang 120 karakter. Migrasi ini aman dijalankan kembali.

Migration `004` tetap menyediakan kode sekali pakai sebagai jalur pemulihan lama. Alur utama memakai migration `005` dan bootstrap HMAC: aplikasi lokal membaca kode kampung dari identitas instalasi, lalu memperoleh kredensial tanpa input operator. Kunci bootstrap hanya dikonfigurasi sekali pada API pusat dan workstation builder; jangan menyimpannya di repository atau membagikannya kepada desa.
