# Pemulihan CSS/JS PWA (HTTP 403)

Jika CSS/JS mengembalikan 403 dengan Content-Type text/html, browser sebenarnya
menerima halaman penolakan akses, bukan stylesheet/script. Jangan menonaktifkan
`nosniff` atau memaksa MIME HTML menjadi CSS. Perbaiki akses file terlebih dahulu.

Deployment lama menjalankan git pull dengan umask 077 dan menyalin permission
source melalui rsync -a. File baru dapat menjadi 600 dan folder 700 sehingga
server web tidak dapat membacanya. Deployment sekarang menormalkan hanya aset
publik, mempertahankan permission runtime privat, dan memeriksa status HTTP/MIME
aset PWA sebelum menyatakan deployment berhasil.

Jalankan melalui SSH Hostinger untuk memulihkan instalasi yang sudah terdeploy,
tanpa mengubah database, VAPID, atau jadwal cron:

```bash
cd ~/repositories/api_warga
git pull --ff-only origin main
php scripts/repair-pwa-assets.php \
  --root="$HOME/domains/warga-smartdesa.mediaverse.co.id/public_html" \
  --url="https://warga-smartdesa.mediaverse.co.id"
```

Script menetapkan folder publik 755, aset statis dan entry point publik 644,
serta .env 600. Folder application, storage, upload, dan session tidak diubah.
Symlink pada aset/entry point ditolak; file rilis yang hilang memerlukan
deployment lengkap. Perintah ini tidak mengubah pengaturan situs di Hostinger.

Jika semua baris pemeriksaan menunjukkan HTTP 200 dengan MIME sesuai, muat ulang
PWA. Bila masih 403, periksa permission folder induk, aturan akses .htaccess,
atau pembatasan hosting. `application/x-javascript` adalah MIME JavaScript yang
masih valid, jadi pemeriksa deployment menerimanya. Berikan output status
pemeriksaan saja, bukan isi `.env`.

Deployment berikutnya tetap memakai perintah biasa:

```bash
bash scripts/deploy-hostinger.sh
```
