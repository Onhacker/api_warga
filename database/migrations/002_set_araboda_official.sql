-- Ubah tenant pilot lama menjadi identitas resmi Kampung Araboda.
-- Aman dijalankan ulang. Jalankan setelah 001_sync_auth.sql.
START TRANSACTION;

SET @old_id = (SELECT id FROM village_tenants WHERE village_code = 'DEMO-ARABODA' LIMIT 1);
SET @official_id = (SELECT id FROM village_tenants WHERE village_code = '95.01.03.2003' LIMIT 1);

-- Jika hanya tenant lama yang ada, pertahankan ID agar seluruh relasi tetap utuh.
UPDATE village_tenants
SET province_code = '95',
    province_name = 'Papua Pegunungan',
    regency_code = '95.01',
    regency_name = 'Jayawijaya',
    district_code = '95.01.03',
    district_name = 'Asologaima',
    village_code = '95.01.03.2003',
    name = 'Kampung Araboda',
    status = 'active'
WHERE id = @old_id AND @official_id IS NULL;

SET @official_id = (SELECT id FROM village_tenants WHERE village_code = '95.01.03.2003' LIMIT 1);
SET @old_id = (SELECT id FROM village_tenants WHERE village_code = 'DEMO-ARABODA' LIMIT 1);

-- Jika kedua kode ada, pindahkan relasi ke tenant resmi sebelum menghapus kode lama.
UPDATE users SET village_id = @official_id
WHERE village_id = @old_id AND @old_id IS NOT NULL AND @official_id IS NOT NULL AND @old_id <> @official_id;
UPDATE citizen_profiles SET village_id = @official_id
WHERE village_id = @old_id AND @old_id IS NOT NULL AND @official_id IS NOT NULL AND @old_id <> @official_id;
UPDATE service_requests SET village_id = @official_id
WHERE village_id = @old_id AND @old_id IS NOT NULL AND @official_id IS NOT NULL AND @old_id <> @official_id;
UPDATE village_installations SET village_id = @official_id
WHERE village_id = @old_id AND @old_id IS NOT NULL AND @official_id IS NOT NULL AND @old_id <> @official_id;
UPDATE sync_messages SET village_id = @official_id
WHERE village_id = @old_id AND @old_id IS NOT NULL AND @official_id IS NOT NULL AND @old_id <> @official_id;

DELETE FROM village_tenants
WHERE id = @old_id AND @old_id IS NOT NULL AND @official_id IS NOT NULL AND @old_id <> @official_id;

-- Pastikan metadata tenant resmi lengkap, termasuk ketika seed sudah pernah dijalankan.
SET @official_id = (SELECT id FROM village_tenants WHERE village_code = '95.01.03.2003' LIMIT 1);
UPDATE village_tenants
SET province_code = '95',
    province_name = 'Papua Pegunungan',
    regency_code = '95.01',
    regency_name = 'Jayawijaya',
    district_code = '95.01.03',
    district_name = 'Asologaima',
    name = 'Kampung Araboda',
    status = 'active'
WHERE id = @official_id;

-- Jika database belum memiliki tenant apa pun, buat baris awal resmi.
INSERT INTO village_tenants (id, province_code, province_name, regency_code, regency_name, district_code, district_name, village_code, name, status)
SELECT '00000000-0000-4000-8000-000000000001', '95', 'Papua Pegunungan', '95.01', 'Jayawijaya', '95.01.03', 'Asologaima', '95.01.03.2003', 'Kampung Araboda', 'active'
WHERE NOT EXISTS (SELECT 1 FROM village_tenants WHERE village_code = '95.01.03.2003');

COMMIT;
