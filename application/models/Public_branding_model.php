<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Public_branding_model extends CI_Model
{
    private $table = 'app_public_branding';

    public function status()
    {
        if (!$this->ready()) return array('success' => FALSE, 'error' => 'migration_required', 'message' => 'Migrasi branding publik belum dijalankan.');
        $row = $this->db->where('id', 1)->limit(1)->get($this->table)->row_array();
        return array('success' => TRUE, 'branding' => $this->normalise($row ?: array()), 'updated_at' => (string) ($row['updated_at'] ?? ''));
    }

    public function publish(array $payload)
    {
        if (!$this->ready()) return array('success' => FALSE, 'error' => 'migration_required', 'message' => 'Migrasi branding publik belum dijalankan.');
        $branding = $this->normalise($payload);
        if ($branding['nama_sistem'] === '') return array('success' => FALSE, 'error' => 'invalid_branding', 'message' => 'Nama sistem wajib diisi.');
        $row = array_merge($branding, array('updated_at' => date('Y-m-d H:i:s')));
        $exists = (int) $this->db->where('id', 1)->count_all_results($this->table) > 0;
        $ok = $exists
            ? $this->db->where('id', 1)->update($this->table, $row)
            : $this->db->insert($this->table, array_merge(array('id' => 1), $row));
        if (!$ok) return array('success' => FALSE, 'error' => 'storage_error', 'message' => 'Branding publik belum dapat disimpan.');
        return array('success' => TRUE, 'message' => 'Branding PWA berhasil diperbarui.', 'branding' => $branding, 'updated_at' => $row['updated_at']);
    }

    private function ready()
    {
        if (!$this->db->table_exists($this->table)) return FALSE;
        foreach (array('id', 'nama_sistem', 'kepanjangan', 'tagline', 'updated_at') as $field) {
            if (!$this->db->field_exists($field, $this->table)) return FALSE;
        }
        return TRUE;
    }

    private function normalise(array $row)
    {
        return array(
            'nama_sistem' => $this->clean(isset($row['nama_sistem']) ? $row['nama_sistem'] : 'SIDAPULIK', 100, 'SIDAPULIK'),
            'kepanjangan' => $this->clean(isset($row['kepanjangan']) ? $row['kepanjangan'] : '', 180),
            'tagline' => $this->clean(isset($row['tagline']) ? $row['tagline'] : '', 255, 'Bersama Membangun Kampung Digital')
        );
    }

    private function clean($value, $max, $fallback = '')
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags((string) $value));
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        $value = function_exists('mb_substr') ? mb_substr($value, 0, (int) $max, 'UTF-8') : substr($value, 0, (int) $max);
        return $value !== '' ? $value : $fallback;
    }
}
