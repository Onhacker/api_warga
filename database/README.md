# Database API Warga

Gunakan schema dan seed dari `/htdocs/smartdesa-warga/database`. File yang disalin di folder ini hanya untuk memudahkan paket API mandiri.

Untuk database yang sudah ada, jalankan `migrations/001_sync_auth.sql` sebelum `API_DEMO_MODE=0`. API produksi membutuhkan kolom `village_installations.sync_secret_encrypted` dan tabel `api_request_nonces`. Jika tenant awal masih memakai kode contoh lama, jalankan `migrations/002_set_araboda_official.sql` sekali. Kode resmi Kampung Araboda adalah `95.01.03.2003`.

Setiap baris aktif pada `village_tenants` mewakili satu kampung/desa yang dapat dipilih warga. Tambahkan wilayah resmi berikutnya ke tabel tersebut, lalu jalankan provisioning untuk setiap instalasi desa yang akan disinkronkan.
