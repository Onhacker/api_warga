# Database API Warga

Gunakan schema dan seed dari `/htdocs/smartdesa-warga/database`. File yang disalin di folder ini hanya untuk memudahkan paket API mandiri.

Untuk database yang sudah ada, jalankan `migrations/001_sync_auth.sql` sebelum `API_DEMO_MODE=0`. API produksi membutuhkan kolom `village_installations.sync_secret_encrypted` dan tabel `api_request_nonces`. Jika tenant awal masih memakai kode contoh lama, jalankan `migrations/002_set_araboda_official.sql` sekali. Setelah itu jalankan `migrations/003_seed_jayawijaya_villages.sql` untuk memasukkan seluruh 332 kampung/kelurahan pada 40 distrik di Kabupaten Jayawijaya, `migrations/004_installation_enrollment.sql`, lalu `migrations/005_auto_enrollment.sql` untuk koneksi otomatis installer universal.

Setiap baris aktif pada `village_tenants` mewakili satu kampung/kelurahan yang dapat dipilih warga. Seed instalasi baru sudah memuat seluruh Kabupaten Jayawijaya. Jalankan provisioning hanya untuk setiap instalasi desa yang akan disinkronkan.

Migration `004` tetap menyediakan kode sekali pakai sebagai jalur pemulihan lama. Alur utama memakai migration `005` dan bootstrap HMAC: aplikasi lokal membaca kode kampung dari identitas instalasi, lalu memperoleh kredensial tanpa input operator. Kunci bootstrap hanya dikonfigurasi sekali pada API pusat dan workstation builder; jangan menyimpannya di repository atau membagikannya kepada desa.
