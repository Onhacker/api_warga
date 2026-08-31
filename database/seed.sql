-- Safe initial reference data. Create real users through an administrative flow.
SET NAMES utf8mb4;

INSERT INTO roles (name, slug, description) VALUES
('Warga', 'warga', 'Mengajukan dan memantau permohonan sendiri.'),
('Sekretaris Desa', 'sekdes', 'Memverifikasi permohonan warga pada desa sendiri.'),
('Kepala Desa', 'kepala-desa', 'Menyetujui atau menolak permohonan desa sendiri.'),
('Administrator Desa', 'admin-desa', 'Mengelola layanan dan pengguna desa sendiri.'),
('Administrator Kabupaten', 'admin-kabupaten', 'Memantau desa dalam satu kabupaten.'),
('Administrator Pusat', 'admin-pusat', 'Mengelola seluruh kabupaten dan konfigurasi pusat.')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description);

INSERT INTO service_types (slug, name, short_name, icon, description, requirements_json, template_key, sort_order, is_active) VALUES
('domisili', 'Surat Keterangan Domisili', 'Domisili', 'fa-home', 'Keterangan tempat tinggal warga.', '["Kartu Keluarga", "Kartu Tanda Penduduk"]', 'surat-keterangan-domisili', 10, 1),
('tidak-mampu', 'Surat Keterangan Tidak Mampu', 'Tidak Mampu', 'fa-hands-helping', 'Keterangan kondisi sosial ekonomi warga.', '["Kartu Keluarga", "Kartu Tanda Penduduk"]', 'surat-keterangan-tidak-mampu', 20, 1),
('usaha', 'Surat Keterangan Usaha', 'Keterangan Usaha', 'fa-store', 'Keterangan kegiatan usaha warga.', '["Kartu Keluarga", "Kartu Tanda Penduduk", "Keterangan lokasi usaha"]', 'surat-keterangan-usaha', 30, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), short_name=VALUES(short_name), icon=VALUES(icon), requirements_json=VALUES(requirements_json), template_key=VALUES(template_key), is_active=VALUES(is_active);

-- Tenant awal resmi. Tambahkan tenant aktif lain untuk kampung berikutnya.
INSERT INTO village_tenants (id, province_code, province_name, regency_code, regency_name, district_code, district_name, village_code, name, status)
VALUES ('00000000-0000-4000-8000-000000000001', '95', 'Papua Pegunungan', '95.01', 'Jayawijaya', '95.01.03', 'Asologaima', '95.01.03.2003', 'Kampung Araboda', 'active')
ON DUPLICATE KEY UPDATE
  province_code=VALUES(province_code), province_name=VALUES(province_name),
  regency_code=VALUES(regency_code), regency_name=VALUES(regency_name),
  district_code=VALUES(district_code), district_name=VALUES(district_name),
  name=VALUES(name), status=VALUES(status);
