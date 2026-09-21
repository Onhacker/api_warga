<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Public_branding_model extends CI_Model
{
    private $table = 'app_public_branding';

    public function status($tenantCode = 'default')
    {
        if (!$this->ready()) return array('success' => FALSE, 'error' => 'migration_required', 'message' => 'Migrasi branding publik belum dijalankan.');
        $tenantCode = $this->normalise_tenant_code($tenantCode);
        if ($tenantCode === FALSE) return array('success' => FALSE, 'error' => 'invalid_tenant', 'message' => 'Kode tenant branding tidak valid.');

        $row = $this->find_tenant($tenantCode);
        $resolvedTenant = $tenantCode;
        if (!$row && $tenantCode !== 'default') {
            $row = $this->find_tenant('default');
            $resolvedTenant = 'default';
        }
        return array(
            'success' => TRUE,
            'branding' => $this->normalise($row ?: array(), $resolvedTenant),
            'requested_tenant' => $tenantCode,
            'resolved_tenant' => $resolvedTenant,
            'updated_at' => (string) ($row['updated_at'] ?? '')
        );
    }

    public function publish(array $payload)
    {
        if (!$this->ready()) return array('success' => FALSE, 'error' => 'migration_required', 'message' => 'Migrasi branding publik belum dijalankan.');
        $tenantCode = $this->normalise_tenant_code(isset($payload['tenant_code']) ? $payload['tenant_code'] : 'default');
        if ($tenantCode === FALSE) return array('success' => FALSE, 'error' => 'invalid_tenant', 'message' => 'Kode tenant branding tidak valid.');
        if (!$this->tenant_ready() && $tenantCode !== 'default') {
            return array('success' => FALSE, 'error' => 'migration_required', 'message' => 'Migrasi branding multi-kabupaten belum dijalankan.');
        }

        $branding = $this->normalise($payload, $tenantCode);
        if ($branding['nama_sistem'] === '') return array('success' => FALSE, 'error' => 'invalid_branding', 'message' => 'Nama sistem wajib diisi.');
        $branding['region_labels_managed'] = 1;
        $row = array(
            'nama_sistem' => $branding['nama_sistem'],
            'kepanjangan' => $branding['kepanjangan'],
            'tagline' => $branding['tagline'],
            'bentuk_lembaga' => $branding['bentuk_lembaga'],
            'bentuk_kecamatan' => $branding['bentuk_kecamatan'],
            'region_labels_managed' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        );
        if ($this->tenant_ready()) {
            $row['tenant_code'] = $tenantCode;
            $row['tenant_name'] = $branding['tenant_name'];
            $exists = (int) $this->db->where('tenant_code', $tenantCode)->count_all_results($this->table) > 0;
            $ok = $exists
                ? $this->db->where('tenant_code', $tenantCode)->update($this->table, $row)
                : $this->db->insert($this->table, $row);
        } else {
            $exists = (int) $this->db->where('id', 1)->count_all_results($this->table) > 0;
            $ok = $exists
                ? $this->db->where('id', 1)->update($this->table, $row)
                : $this->db->insert($this->table, array_merge(array('id' => 1), $row));
        }
        if (!$ok) return array('success' => FALSE, 'error' => 'storage_error', 'message' => 'Branding publik belum dapat disimpan.');
        return array('success' => TRUE, 'message' => 'Branding PWA tenant ' . $tenantCode . ' berhasil diperbarui.', 'branding' => $branding, 'updated_at' => $row['updated_at']);
    }

    private function ready()
    {
        if (!$this->db->table_exists($this->table)) return FALSE;
        foreach (array('id', 'nama_sistem', 'kepanjangan', 'tagline', 'bentuk_lembaga', 'bentuk_kecamatan', 'region_labels_managed', 'updated_at') as $field) {
            if (!$this->db->field_exists($field, $this->table)) return FALSE;
        }
        return TRUE;
    }

    private function tenant_ready()
    {
        return $this->db->field_exists('tenant_code', $this->table)
            && $this->db->field_exists('tenant_name', $this->table);
    }

    private function find_tenant($tenantCode)
    {
        if ($this->tenant_ready()) {
            return $this->db->where('tenant_code', $tenantCode)->limit(1)->get($this->table)->row_array();
        }
        return $this->db->where('id', 1)->limit(1)->get($this->table)->row_array();
    }

    private function normalise(array $row, $tenantCode = 'default')
    {
        return array(
            'tenant_code' => $tenantCode,
            'tenant_name' => $this->clean(isset($row['tenant_name']) ? $row['tenant_name'] : '', 120),
            'nama_sistem' => $this->clean(isset($row['nama_sistem']) ? $row['nama_sistem'] : 'SIDAPULIK', 100, 'SIDAPULIK'),
            'kepanjangan' => $this->clean(isset($row['kepanjangan']) ? $row['kepanjangan'] : '', 180),
            'tagline' => $this->clean(isset($row['tagline']) ? $row['tagline'] : '', 255, 'Bersama Membangun Kampung Digital'),
            'bentuk_lembaga' => $this->label(isset($row['bentuk_lembaga']) ? $row['bentuk_lembaga'] : '', 'Desa'),
            'bentuk_kecamatan' => $this->label(isset($row['bentuk_kecamatan']) ? $row['bentuk_kecamatan'] : '', 'Kecamatan'),
            'region_labels_managed' => !empty($row['region_labels_managed']) ? 1 : 0
        );
    }

    private function normalise_tenant_code($value)
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '' || strtolower($value) === 'default') return 'default';
        if (preg_match('/^[0-9]{4}$/', $value)) $value = substr($value, 0, 2) . '.' . substr($value, 2, 2);
        if (preg_match('/^([0-9]{2}\.[0-9]{2})(?:\.|$)/', $value, $match)) $value = $match[1];
        return preg_match('/^[A-Z0-9][A-Z0-9._-]{1,29}$/', $value) ? $value : FALSE;
    }

    private function clean($value, $max, $fallback = '')
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags((string) $value));
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        $value = function_exists('mb_substr') ? mb_substr($value, 0, (int) $max, 'UTF-8') : substr($value, 0, (int) $max);
        return $value !== '' ? $value : $fallback;
    }

    private function label($value, $fallback)
    {
        $value = $this->clean($value, 100, $fallback);
        return function_exists('mb_convert_case')
            ? mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($value));
    }
}
