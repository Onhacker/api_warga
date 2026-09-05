-- Simpan salinan terenkripsi NIK dan No. KK agar pemilik akun dapat melihat
-- data identitasnya sendiri. Nilai plaintext tidak pernah ditulis ke log,
-- tabel direktori penduduk, atau riwayat sinkronisasi.
SET NAMES utf8mb4;

ALTER TABLE citizen_profiles
  ADD COLUMN IF NOT EXISTS nik_encrypted VARBINARY(512) NULL,
  ADD COLUMN IF NOT EXISTS kk_encrypted VARBINARY(512) NULL AFTER nik_encrypted;
