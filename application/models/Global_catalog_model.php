<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Katalog layanan tunggal yang dipakai semua kampung.
 *
 * Hanya endpoint monitoring server pusat yang boleh menulis tabel ini. Client
 * desa tetap menyinkronkan transaksi, tetapi tidak pernah menjadi penerbit
 * definisi layanan.
 */
class Global_catalog_model extends CI_Model
{
    public function status()
    {
        if (!$this->schema_ready()) {
            return array(
                'success' => FALSE,
                'error' => 'migration_required',
                'message' => 'Migrasi katalog global 022 belum dijalankan.'
            );
        }

        $state = $this->db->where('id', 1)->limit(1)->get('global_service_catalog_state')->row_array();
        $active = (int) $this->db->where('is_active', 1)->count_all_results('service_types');
        return array(
            'success' => TRUE,
            'ready' => $state && (int) $state['is_ready'] === 1,
            'revision' => $state ? (int) $state['last_revision'] : 0,
            'catalog_hash' => $state ? (string) $state['last_hash'] : '',
            'service_count' => $active,
            'published_by' => $state ? (string) $state['published_by'] : '',
            'published_at' => $state ? (string) $state['published_at'] : ''
        );
    }

    public function publish(array $payload)
    {
        if (!$this->schema_ready()) {
            return array(
                'success' => FALSE,
                'error' => 'migration_required',
                'message' => 'Migrasi katalog global 022 belum dijalankan.'
            );
        }

        $services = isset($payload['services']) && is_array($payload['services']) ? $payload['services'] : NULL;
        if ($services === NULL || empty($services) || count($services) > 200) {
            return array('success' => FALSE, 'error' => 'invalid_catalog', 'message' => 'Isi katalog global tidak valid. Katalog pusat tidak boleh kosong.');
        }

        $providedHash = strtolower(trim((string) (isset($payload['catalog_hash']) ? $payload['catalog_hash'] : '')));
        $computedHash = hash('sha256', json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!preg_match('/^[a-f0-9]{64}$/', $providedHash) || !hash_equals($computedHash, $providedHash)) {
            return array('success' => FALSE, 'error' => 'invalid_catalog_hash', 'message' => 'Hash katalog global tidak sesuai dengan isinya.');
        }

        $normalised = array();
        $keys = array();
        foreach ($services as $index => $service) {
            $result = $this->normalise_service($service, $index, $keys);
            if (empty($result['success'])) return $result;
            $normalised[] = $result['service'];
            $keys[$result['service']['slug']] = TRUE;
        }

        $actor = $this->clean_text(isset($payload['published_by']) ? $payload['published_by'] : 'Super Admin', 160);
        if ($actor === '') $actor = 'Super Admin';
        $now = date('Y-m-d H:i:s');

        $this->db->trans_begin();
        $state = $this->db->query('SELECT * FROM global_service_catalog_state WHERE id=1 FOR UPDATE')->row_array();
        if (!$state) {
            $this->db->insert('global_service_catalog_state', array('id' => 1));
            $state = $this->db->query('SELECT * FROM global_service_catalog_state WHERE id=1 FOR UPDATE')->row_array();
        }
        if (!$state || !$this->db->trans_status()) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'error' => 'storage_error', 'message' => 'Status katalog global belum dapat dikunci.');
        }

        $lastHash = strtolower(trim((string) $state['last_hash']));
        if ($lastHash !== '' && hash_equals($lastHash, $providedHash)) {
            $this->db->trans_commit();
            return array(
                'success' => TRUE,
                'already_published' => TRUE,
                'revision' => (int) $state['last_revision'],
                'service_count' => (int) $state['service_count'],
                'catalog_hash' => $providedHash,
                'published_at' => (string) $state['published_at'],
                'message' => 'Katalog global sudah menggunakan isi terbaru.'
            );
        }

        $revision = max(0, (int) $state['last_revision']) + 1;
        foreach ($normalised as $service) {
            $row = $this->db->where('slug', $service['slug'])->limit(1)->get('service_types')->row_array();
            $data = array(
                'name' => $service['name'],
                'short_name' => $service['short_name'],
                'icon' => $service['icon'],
                'description' => $service['description'] !== '' ? $service['description'] : NULL,
                'requirements_json' => json_encode($service['requirements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'form_schema_json' => json_encode($service['form_schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'template_key' => $service['template_key'],
                'schema_version' => (int) $service['form_schema']['version'],
                'sort_order' => $service['sort_order'],
                'is_active' => 1,
                'submission_enabled' => $service['submission_enabled'],
                'availability_note' => $service['availability_note'] !== '' ? $service['availability_note'] : NULL,
                'minimum_app_version' => $service['minimum_app_version'],
                'source_updated_at' => $service['source_updated_at'],
                'published_at' => $now,
                'source_hash' => $service['source_hash'],
                'source_revision' => $revision
            );
            if ($row) {
                $ok = $this->db->where('id', (int) $row['id'])->update('service_types', $data);
            } else {
                $data['slug'] = $service['slug'];
                $ok = $this->db->insert('service_types', $data);
            }
            if (!$ok || !$this->db->trans_status()) {
                $this->db->trans_rollback();
                return array('success' => FALSE, 'error' => 'storage_error', 'message' => 'Layanan ' . $service['name'] . ' belum dapat disimpan.');
            }
        }

        $this->db->where('is_active', 1);
        if (!empty($keys)) $this->db->where_not_in('slug', array_keys($keys));
        $this->db->update('service_types', array('is_active' => 0, 'published_at' => $now, 'source_revision' => $revision));

        $saved = $this->db->where('id', 1)->update('global_service_catalog_state', array(
            'is_ready' => 1,
            'last_revision' => $revision,
            'last_hash' => $providedHash,
            'service_count' => count($normalised),
            'published_by' => $actor,
            'published_at' => $now
        ));
        if (!$saved || !$this->db->trans_status()) {
            $this->db->trans_rollback();
            return array('success' => FALSE, 'error' => 'storage_error', 'message' => 'Status katalog global belum dapat disimpan.');
        }
        if (!$this->db->trans_commit()) {
            return array('success' => FALSE, 'error' => 'storage_error', 'message' => 'Publikasi katalog global belum dapat diselesaikan.');
        }

        return array(
            'success' => TRUE,
            'already_published' => FALSE,
            'revision' => $revision,
            'service_count' => count($normalised),
            'catalog_hash' => $providedHash,
            'published_at' => $now,
            'message' => count($normalised) . ' layanan berhasil diterbitkan sebagai katalog global.'
        );
    }

    private function schema_ready()
    {
        foreach (array('service_types', 'global_service_catalog_state', 'village_service_overrides') as $table) {
            if (!$this->db->table_exists($table)) return FALSE;
        }
        foreach (array('form_schema_json', 'schema_version', 'submission_enabled', 'minimum_app_version', 'source_revision') as $field) {
            if (!$this->db->field_exists($field, 'service_types')) return FALSE;
        }
        return TRUE;
    }

    private function normalise_service($service, $index, array $seen)
    {
        if (!is_array($service)) return $this->invalid('Layanan katalog nomor ' . ((int) $index + 1) . ' belum valid.');
        $slug = strtolower(trim((string) (isset($service['service_key']) ? $service['service_key'] : '')));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $slug) || isset($seen[$slug])) {
            return $this->invalid('Kunci layanan katalog tidak valid atau duplikat.');
        }
        $name = $this->clean_text(isset($service['name']) ? $service['name'] : '', 180);
        $shortName = $this->clean_text(isset($service['short_name']) ? $service['short_name'] : '', 100);
        if ($name === '' || $shortName === '') return $this->invalid('Nama layanan katalog wajib diisi.');

        $schemaError = '';
        $schema = $this->normalise_schema(isset($service['form_schema']) ? $service['form_schema'] : array(), $schemaError);
        if ($schema === FALSE) return $this->invalid($schemaError !== '' ? $schemaError : 'Form layanan katalog belum valid.');

        $requirements = array();
        $seenRequirements = array();
        $rawRequirements = isset($service['requirements']) ? $service['requirements'] : array();
        if (!is_array($rawRequirements) || count($rawRequirements) > 50) return $this->invalid('Persyaratan layanan tidak valid atau melebihi batas.');
        foreach ($rawRequirements as $requirement) {
            if (!is_scalar($requirement)) return $this->invalid('Persyaratan layanan tidak valid.');
            $requirement = $this->clean_text($requirement, 180);
            $key = function_exists('mb_strtolower') ? mb_strtolower($requirement, 'UTF-8') : strtolower($requirement);
            if ($requirement === '' || isset($seenRequirements[$key])) return $this->invalid('Persyaratan layanan tidak boleh kosong atau duplikat.');
            $seenRequirements[$key] = TRUE;
            $requirements[] = $requirement;
        }

        $icon = trim((string) (isset($service['icon']) ? $service['icon'] : 'fa-file-alt'));
        if (!preg_match('/^[A-Za-z0-9 _-]{1,80}$/', $icon)) $icon = 'fa-file-alt';
        $templateKey = $this->catalog_key(isset($service['template_key']) ? $service['template_key'] : $slug, 120);
        if ($templateKey === '') $templateKey = $slug;
        $minimumVersion = trim((string) (isset($service['minimum_app_version']) ? $service['minimum_app_version'] : '0.0.0'));
        if (!$this->valid_version($minimumVersion)) return $this->invalid('Versi minimum aplikasi untuk ' . $name . ' tidak valid.');
        $sourceHash = strtolower(trim((string) (isset($service['source_hash']) ? $service['source_hash'] : '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $sourceHash)) {
            $sourceHash = hash('sha256', json_encode($service, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return array('success' => TRUE, 'service' => array(
            'slug' => $slug,
            'name' => $name,
            'short_name' => $shortName,
            'icon' => $icon,
            'description' => $this->clean_text(isset($service['description']) ? $service['description'] : '', 1000),
            'requirements' => $requirements,
            'form_schema' => $schema,
            'template_key' => $templateKey,
            'sort_order' => (int) $index,
            'submission_enabled' => isset($service['submission_enabled']) ? (!empty($service['submission_enabled']) ? 1 : 0) : 1,
            'availability_note' => $this->clean_text(isset($service['availability_note']) ? $service['availability_note'] : '', 500),
            'minimum_app_version' => $minimumVersion,
            'source_updated_at' => $this->date_time(isset($service['source_updated_at']) ? $service['source_updated_at'] : ''),
            'source_hash' => $sourceHash
        ));
    }

    private function normalise_schema($raw, &$error)
    {
        $error = '';
        if (!is_array($raw) || (isset($raw['fields']) && !is_array($raw['fields']))) {
            $error = 'Struktur formulir layanan tidak valid.';
            return FALSE;
        }
        $version = isset($raw['version']) ? (int) $raw['version'] : 1;
        $fields = isset($raw['fields']) ? $raw['fields'] : array();
        if ($version < 1 || $version > 99 || count($fields) > 30) {
            $error = 'Versi atau jumlah isian formulir tidak valid.';
            return FALSE;
        }
        $allowed = array('text', 'textarea', 'date', 'select', 'number', 'tel', 'email', 'file');
        $reserved = array('service_type', 'purpose', 'note', 'csrf_token', 'warga_fields', 'warga_files');
        $seen = array();
        $result = array();
        foreach ($fields as $field) {
            if (!is_array($field)) { $error = 'Struktur isian formulir tidak valid.'; return FALSE; }
            $key = strtolower(trim((string) (isset($field['key']) ? $field['key'] : '')));
            $label = $this->clean_text(isset($field['label']) ? $field['label'] : '', 180);
            $type = strtolower(trim((string) (isset($field['type']) ? $field['type'] : 'text')));
            if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key) || in_array($key, $reserved, TRUE)
                || isset($seen[$key]) || $label === '' || !in_array($type, $allowed, TRUE)) {
                $error = 'Kunci, label, atau tipe isian formulir tidak valid.';
                return FALSE;
            }
            $options = array();
            if ($type === 'select') {
                $rawOptions = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
                if (empty($rawOptions) || count($rawOptions) > 50) { $error = 'Pilihan formulir belum valid.'; return FALSE; }
                $optionKeys = array();
                foreach ($rawOptions as $option) {
                    if (!is_array($option)) { $error = 'Pilihan formulir tidak valid.'; return FALSE; }
                    $value = $this->clean_text(isset($option['value']) ? $option['value'] : '', 100);
                    $optionLabel = $this->clean_text(isset($option['label']) ? $option['label'] : $value, 180);
                    $optionKey = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
                    if ($value === '' || $optionLabel === '' || isset($optionKeys[$optionKey])) { $error = 'Pilihan formulir tidak boleh kosong atau duplikat.'; return FALSE; }
                    $optionKeys[$optionKey] = TRUE;
                    $options[] = array('value' => $value, 'label' => $optionLabel);
                }
            }
            $accept = strtolower(trim((string) (isset($field['accept']) ? $field['accept'] : '')));
            $cleanAccept = array();
            if ($type === 'file') {
                $allowedAccept = array('image/jpeg', 'image/png', 'image/*', 'application/pdf', '.jpg', '.jpeg', '.png', '.pdf');
                foreach ($accept === '' ? array() : preg_split('/\s*,\s*/', $accept) as $part) {
                    if ($part === '' || !in_array($part, $allowedAccept, TRUE)) { $error = 'Batas tipe berkas formulir tidak valid.'; return FALSE; }
                    if (!in_array($part, $cleanAccept, TRUE)) $cleanAccept[] = $part;
                }
                if (empty($cleanAccept)) $cleanAccept = array('image/jpeg', 'image/png', 'application/pdf');
            } elseif ($accept !== '') {
                $error = 'Batas tipe berkas hanya boleh digunakan pada isian berkas.';
                return FALSE;
            }
            $seen[$key] = TRUE;
            $result[] = array(
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => !empty($field['required']),
                'options' => $options,
                'help' => $this->clean_text(isset($field['help']) ? $field['help'] : '', 500),
                'placeholder' => $this->clean_text(isset($field['placeholder']) ? $field['placeholder'] : '', 180),
                'accept' => $type === 'file' ? implode(',', $cleanAccept) : '',
                'max_length' => $type === 'file' ? 0 : max(1, min(5000, (int) (isset($field['max_length']) ? $field['max_length'] : 500))),
                'max_size_mb' => $type === 'file' ? max(1, min(10, (int) (isset($field['max_size_mb']) ? $field['max_size_mb'] : 5))) : 0,
                'multiple' => $type === 'file' && !empty($field['multiple']),
                'binding' => $this->catalog_key(isset($field['binding']) ? $field['binding'] : '', 120)
            );
        }
        return array('version' => $version, 'fields' => $result);
    }

    private function valid_version($value)
    {
        return preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', (string) $value) === 1;
    }

    private function catalog_key($value, $length)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        return trim(substr($value, 0, (int) $length), '-_');
    }

    private function clean_text($value, $length)
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return function_exists('mb_substr') ? mb_substr($value, 0, (int) $length, 'UTF-8') : substr($value, 0, (int) $length);
    }

    private function date_time($value)
    {
        $value = trim((string) $value);
        if ($value === '') return NULL;
        $time = strtotime($value);
        return $time === FALSE ? NULL : date('Y-m-d H:i:s', $time);
    }

    private function invalid($message)
    {
        return array('success' => FALSE, 'error' => 'invalid_catalog', 'message' => (string) $message);
    }
}
