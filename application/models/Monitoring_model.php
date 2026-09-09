<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Read-only, privacy-minimized metrics for the SmartDesa central dashboard.
 *
 * This model deliberately never selects NIK, No. KK, passwords, request
 * payloads, document paths, or push subscription values.
 */
class Monitoring_model extends CI_Model
{
    private $tableCache = array();

    public function summary(array $payload)
    {
        $selectors = $this->normalise_selectors(isset($payload['regencies']) ? $payload['regencies'] : array());
        if (empty($selectors)) {
            return array('success' => FALSE, 'error' => 'invalid_scope', 'message' => 'Daftar kabupaten monitoring belum valid.');
        }

        $selected = $this->normalise_selector(isset($payload['selected_regency']) ? $payload['selected_regency'] : NULL);
        if ($selected !== NULL && !$this->selector_list_matches($selectors, $selected)) {
            return array('success' => FALSE, 'error' => 'invalid_scope', 'message' => 'Kabupaten yang dipilih tidak termasuk daftar kabupaten terdaftar.');
        }

        $tenants = $this->load_tenants($selectors, $selected);
        if ($tenants === FALSE) {
            return array('success' => FALSE, 'error' => 'service_unavailable', 'message' => 'Daftar kampung pada API belum dapat dimuat.');
        }

        $villageIds = array();
        foreach ($tenants as $tenant) {
            $id = trim((string) (isset($tenant['id']) ? $tenant['id'] : ''));
            if ($id !== '') $villageIds[$id] = TRUE;
        }
        $villageIds = array_keys($villageIds);

        $installations = $this->installation_metrics($villageIds);
        $residents = $this->count_map('village_resident_directory', 'village_id', $villageIds, array('status' => 'active'));
        $catalog = $this->catalog_metrics($villageIds);
        $staff = $this->count_map('warga_staff_sources', 'village_id', $villageIds, array(), 'user_id');
        $citizenAccounts = $this->count_map('citizen_profiles', 'village_id', $villageIds, array(), 'user_id');
        $requests = $this->request_metrics($villageIds);
        $queue = $this->queue_metrics($villageIds);

        $rows = array();
        foreach ($tenants as $tenant) {
            $villageId = trim((string) (isset($tenant['id']) ? $tenant['id'] : ''));
            if ($villageId === '') continue;

            $installation = isset($installations[$villageId]) ? $installations[$villageId] : $this->empty_installation_metric();
            $request = isset($requests[$villageId]) ? $requests[$villageId] : array('total' => 0, 'open' => 0, 'statuses' => array());
            $queueMetric = isset($queue[$villageId]) ? $queue[$villageId] : array('pending_inbound' => 0, 'pending_outbound' => 0, 'failed' => 0);

            $rows[] = array(
                'village_code' => (string) (isset($tenant['village_code']) ? $tenant['village_code'] : ''),
                'village_name' => (string) (isset($tenant['village_name']) ? $tenant['village_name'] : ''),
                'district_code' => (string) (isset($tenant['district_code']) ? $tenant['district_code'] : ''),
                'district_name' => (string) (isset($tenant['district_name']) ? $tenant['district_name'] : ''),
                'regency_code' => (string) (isset($tenant['regency_code']) ? $tenant['regency_code'] : ''),
                'regency_name' => (string) (isset($tenant['regency_name']) ? $tenant['regency_name'] : ''),
                'installation_status' => $installation['installation_status'],
                'installation_count' => (int) $installation['installation_count'],
                'installation_code' => $installation['installation_code'],
                'connection_status' => $installation['connection_status'],
                'app_version' => $installation['app_version'],
                'last_seen_at' => $installation['last_seen_at'],
                'last_sync_at' => $installation['last_sync_at'],
                'resident_count' => isset($residents[$villageId]) ? (int) $residents[$villageId] : 0,
                'catalog_count' => isset($catalog[$villageId]) ? (int) $catalog[$villageId] : 0,
                'staff_count' => isset($staff[$villageId]) ? (int) $staff[$villageId] : 0,
                'citizen_account_count' => isset($citizenAccounts[$villageId]) ? (int) $citizenAccounts[$villageId] : 0,
                'request_total' => (int) $request['total'],
                'request_open' => (int) $request['open'],
                'request_statuses' => $request['statuses'],
                'pending_inbound' => (int) $queueMetric['pending_inbound'],
                'pending_outbound' => (int) $queueMetric['pending_outbound'],
                'failed_messages' => (int) $queueMetric['failed']
            );
        }

        $recordsTotal = count($rows);
        $search = $this->clean_text(isset($payload['search']) ? $payload['search'] : '', 100);
        $filteredRows = $this->filter_rows($rows, $search);
        $recordsFiltered = count($filteredRows);
        $this->sort_rows($filteredRows, isset($payload['order_col']) ? $payload['order_col'] : 'village_name', isset($payload['order_dir']) ? $payload['order_dir'] : 'asc');

        $start = max(0, (int) (isset($payload['start']) ? $payload['start'] : 0));
        $length = (int) (isset($payload['length']) ? $payload['length'] : 25);
        $length = $length <= 0 ? 25 : min(100, $length);
        $pageRows = array_slice($filteredRows, $start, $length);

        return array(
            'success' => TRUE,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'villages' => $pageRows,
            'summary' => $this->build_summary($rows, $selectors),
            'regencies' => $this->build_regency_summary($rows, $selectors)
        );
    }

    public function village_options(array $payload)
    {
        $selectors = $this->normalise_selectors(isset($payload['regencies']) ? $payload['regencies'] : array());
        if (empty($selectors)) {
            return array('success' => FALSE, 'error' => 'invalid_scope', 'message' => 'Daftar kabupaten monitoring belum valid.');
        }

        $tenants = $this->load_tenants($selectors, NULL);
        if ($tenants === FALSE) {
            return array('success' => FALSE, 'error' => 'service_unavailable', 'message' => 'Daftar kampung pada API belum dapat dimuat.');
        }

        $villageIds = array();
        foreach ($tenants as $tenant) {
            $id = trim((string) (isset($tenant['id']) ? $tenant['id'] : ''));
            if ($id !== '') $villageIds[] = $id;
        }
        $installations = $this->installation_metrics($villageIds);
        $villages = array();
        foreach ($tenants as $tenant) {
            $villageId = trim((string) (isset($tenant['id']) ? $tenant['id'] : ''));
            $installation = isset($installations[$villageId])
                ? $installations[$villageId] : $this->empty_installation_metric();
            $villages[] = array(
                'village_code' => (string) (isset($tenant['village_code']) ? $tenant['village_code'] : ''),
                'village_name' => (string) (isset($tenant['village_name']) ? $tenant['village_name'] : ''),
                'district_code' => (string) (isset($tenant['district_code']) ? $tenant['district_code'] : ''),
                'district_name' => (string) (isset($tenant['district_name']) ? $tenant['district_name'] : ''),
                'regency_code' => (string) (isset($tenant['regency_code']) ? $tenant['regency_code'] : ''),
                'regency_name' => (string) (isset($tenant['regency_name']) ? $tenant['regency_name'] : ''),
                'installation_status' => (string) $installation['installation_status']
            );
        }
        return array('success' => TRUE, 'villages' => $villages);
    }

    private function load_tenants(array $selectors, $selected)
    {
        if (!$this->table_ready('village_tenants')) return FALSE;

        $query = $this->db
            ->select('id, province_code, province_name, regency_code, regency_name, district_code, district_name, village_code, name AS village_name')
            ->from('village_tenants')
            ->where('status', 'active')
            ->order_by('regency_name', 'ASC')
            ->order_by('district_name', 'ASC')
            ->order_by('name', 'ASC')
            ->get();
        if (!$query) {
            log_message('error', 'Monitoring gagal memuat village_tenants: ' . json_encode($this->db->error()));
            return FALSE;
        }

        $rows = array();
        foreach ($query->result_array() as $row) {
            $matched = FALSE;
            foreach ($selectors as $selector) {
                if ($this->tenant_matches_selector($row, $selector)) {
                    $matched = TRUE;
                    break;
                }
            }
            if (!$matched) continue;
            if ($selected !== NULL && !$this->tenant_matches_selector($row, $selected)) continue;
            $rows[] = $row;
        }
        return $rows;
    }

    private function installation_metrics(array $villageIds)
    {
        $result = array();
        if (empty($villageIds) || !$this->table_ready('village_installations')) return $result;

        $fields = $this->table_fields('village_installations');
        $wanted = array('id', 'village_id', 'installation_code', 'status', 'app_version', 'last_seen_at', 'last_sync_at', 'updated_at');
        $available = array_values(array_intersect($wanted, $fields));
        if (!in_array('village_id', $available, TRUE)) return $result;

        $query = $this->db
            ->select(implode(', ', $available))
            ->from('village_installations')
            ->where_in('village_id', $villageIds)
            ->get();
        if (!$query) return $result;

        $grouped = array();
        foreach ($query->result_array() as $row) {
            $villageId = trim((string) (isset($row['village_id']) ? $row['village_id'] : ''));
            if ($villageId === '') continue;
            if (!isset($grouped[$villageId])) $grouped[$villageId] = array();
            $grouped[$villageId][] = $row;
        }

        foreach ($grouped as $villageId => $rows) {
            usort($rows, function ($a, $b) {
                $aTime = strtotime((string) (isset($a['last_seen_at']) ? $a['last_seen_at'] : (isset($a['updated_at']) ? $a['updated_at'] : ''))) ?: 0;
                $bTime = strtotime((string) (isset($b['last_seen_at']) ? $b['last_seen_at'] : (isset($b['updated_at']) ? $b['updated_at'] : ''))) ?: 0;
                return $bTime <=> $aTime;
            });

            $active = array_values(array_filter($rows, function ($row) {
                return strtolower(trim((string) (isset($row['status']) ? $row['status'] : ''))) === 'active';
            }));
            $chosen = !empty($active) ? $active[0] : $rows[0];
            $installationStatus = empty($active) ? 'inactive' : (count($active) > 1 ? 'multiple_active' : 'active');
            $lastSeen = trim((string) (isset($chosen['last_seen_at']) ? $chosen['last_seen_at'] : ''));
            $connectionStatus = $this->connection_status($lastSeen);

            $result[$villageId] = array(
                'installation_status' => $installationStatus,
                'installation_count' => count($rows),
                'installation_code' => $this->mask_code(isset($chosen['installation_code']) ? $chosen['installation_code'] : ''),
                'connection_status' => $connectionStatus,
                'app_version' => trim((string) (isset($chosen['app_version']) ? $chosen['app_version'] : '')),
                'last_seen_at' => $lastSeen,
                'last_sync_at' => trim((string) (isset($chosen['last_sync_at']) ? $chosen['last_sync_at'] : ''))
            );
        }
        return $result;
    }

    private function empty_installation_metric()
    {
        return array(
            'installation_status' => 'not_provisioned',
            'installation_count' => 0,
            'installation_code' => '',
            'connection_status' => 'never_seen',
            'app_version' => '',
            'last_seen_at' => '',
            'last_sync_at' => ''
        );
    }

    private function connection_status($lastSeen)
    {
        if (trim((string) $lastSeen) === '') return 'never_seen';
        $minutes = min(1440, max(5, (int) (getenv('WARGA_MONITOR_ONLINE_MINUTES') ?: 30)));
        $time = strtotime((string) $lastSeen);
        if ($time !== FALSE && $time >= time() - ($minutes * 60)) return 'online';
        return 'stale';
    }

    private function catalog_metrics(array $villageIds)
    {
        $result = array();
        if (empty($villageIds) || !$this->table_ready('village_service_catalog')) return $result;
        $fields = $this->table_fields('village_service_catalog');
        if (!in_array('village_id', $fields, TRUE)) return $result;

        $this->db->select('village_id, COUNT(*) AS total', FALSE)->from('village_service_catalog')->where_in('village_id', $villageIds);
        if (in_array('is_active', $fields, TRUE)) $this->db->where('is_active', 1);
        if (in_array('submission_enabled', $fields, TRUE)) $this->db->where('submission_enabled', 1);
        $query = $this->db->group_by('village_id')->get();
        if (!$query) return $result;
        foreach ($query->result_array() as $row) $result[(string) $row['village_id']] = (int) $row['total'];
        return $result;
    }

    private function request_metrics(array $villageIds)
    {
        $result = array();
        if (empty($villageIds) || !$this->table_ready('service_requests')) return $result;
        $query = $this->db->select('village_id, status, COUNT(*) AS total', FALSE)
            ->from('service_requests')->where_in('village_id', $villageIds)
            ->group_by(array('village_id', 'status'))->get();
        if (!$query) return $result;

        $terminal = array('rejected' => TRUE, 'cancelled' => TRUE, 'completed' => TRUE, 'issued' => TRUE);
        foreach ($query->result_array() as $row) {
            $villageId = (string) $row['village_id'];
            $status = strtolower(trim((string) $row['status']));
            if (!isset($result[$villageId])) $result[$villageId] = array('total' => 0, 'open' => 0, 'statuses' => array());
            $total = (int) $row['total'];
            $result[$villageId]['total'] += $total;
            if (!isset($terminal[$status])) $result[$villageId]['open'] += $total;
            $result[$villageId]['statuses'][$status] = $total;
        }
        return $result;
    }

    private function queue_metrics(array $villageIds)
    {
        $result = array();
        if (empty($villageIds) || !$this->table_ready('sync_messages')) return $result;
        $query = $this->db->select('village_id, direction, status, COUNT(*) AS total', FALSE)
            ->from('sync_messages')->where_in('village_id', $villageIds)
            ->group_by(array('village_id', 'direction', 'status'))->get();
        if (!$query) return $result;

        foreach ($query->result_array() as $row) {
            $villageId = (string) $row['village_id'];
            $direction = strtolower(trim((string) $row['direction']));
            $status = strtolower(trim((string) $row['status']));
            if (!isset($result[$villageId])) $result[$villageId] = array('pending_inbound' => 0, 'pending_outbound' => 0, 'failed' => 0);
            $total = (int) $row['total'];
            if ($status === 'failed') $result[$villageId]['failed'] += $total;
            if ($status === 'pending' || $status === 'processing') {
                if ($direction === 'cloud_to_local') $result[$villageId]['pending_inbound'] += $total;
                if ($direction === 'local_to_cloud') $result[$villageId]['pending_outbound'] += $total;
            }
        }
        return $result;
    }

    private function count_map($table, $groupField, array $villageIds, array $where = array(), $distinctField = '')
    {
        $result = array();
        if (empty($villageIds) || !$this->table_ready($table)) return $result;
        $fields = $this->table_fields($table);
        if (!in_array($groupField, $fields, TRUE)) return $result;
        $count = $distinctField !== '' && in_array($distinctField, $fields, TRUE)
            ? 'COUNT(DISTINCT ' . $distinctField . ') AS total'
            : 'COUNT(*) AS total';
        $this->db->select($groupField . ', ' . $count, FALSE)->from($table)->where_in($groupField, $villageIds);
        foreach ($where as $field => $value) {
            if (in_array($field, $fields, TRUE)) $this->db->where($field, $value);
        }
        $query = $this->db->group_by($groupField)->get();
        if (!$query) return $result;
        foreach ($query->result_array() as $row) $result[(string) $row[$groupField]] = (int) $row['total'];
        return $result;
    }

    private function filter_rows(array $rows, $search)
    {
        $search = $this->normalise_name($search);
        if ($search === '') return $rows;
        $filtered = array();
        foreach ($rows as $row) {
            $haystack = $this->normalise_name(implode(' ', array(
                isset($row['village_code']) ? $row['village_code'] : '',
                isset($row['village_name']) ? $row['village_name'] : '',
                isset($row['district_code']) ? $row['district_code'] : '',
                isset($row['district_name']) ? $row['district_name'] : '',
                isset($row['regency_code']) ? $row['regency_code'] : '',
                isset($row['regency_name']) ? $row['regency_name'] : '',
                isset($row['installation_status']) ? $row['installation_status'] : '',
                isset($row['connection_status']) ? $row['connection_status'] : '',
                isset($row['app_version']) ? $row['app_version'] : ''
            )));
            if (strpos($haystack, $search) !== FALSE) $filtered[] = $row;
        }
        return $filtered;
    }

    private function sort_rows(&$rows, $column, $direction)
    {
        $allowed = array('regency_name', 'district_name', 'village_name', 'installation_status', 'connection_status', 'app_version', 'last_seen_at', 'last_sync_at', 'resident_count', 'request_total', 'failed_messages');
        $column = in_array($column, $allowed, TRUE) ? $column : 'village_name';
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        usort($rows, function ($a, $b) use ($column, $direction) {
            $left = isset($a[$column]) ? $a[$column] : '';
            $right = isset($b[$column]) ? $b[$column] : '';
            if (is_numeric($left) && is_numeric($right)) $compare = ((float) $left <=> (float) $right);
            else $compare = strcasecmp((string) $left, (string) $right);
            if ($compare === 0) $compare = strcasecmp((string) $a['village_name'], (string) $b['village_name']);
            return $direction === 'desc' ? -$compare : $compare;
        });
    }

    private function build_summary(array $rows, array $selectors)
    {
        $summary = array(
            'registered_regencies' => count($selectors),
            'matched_regencies' => 0,
            'total_villages' => count($rows),
            'installed' => 0,
            'online' => 0,
            'stale' => 0,
            'not_provisioned' => 0,
            'resident_count' => 0,
            'catalog_count' => 0,
            'staff_count' => 0,
            'citizen_account_count' => 0,
            'request_total' => 0,
            'request_open' => 0,
            'pending_inbound' => 0,
            'pending_outbound' => 0,
            'failed_messages' => 0,
            'generated_at' => date('c'),
            'online_window_minutes' => min(1440, max(5, (int) (getenv('WARGA_MONITOR_ONLINE_MINUTES') ?: 30)))
        );
        $regencies = array();
        foreach ($rows as $row) {
            $key = $this->selector_row_key($row, $selectors);
            $regencies[$key] = TRUE;
            if ($row['installation_status'] === 'active' || $row['installation_status'] === 'multiple_active') $summary['installed']++;
            if ($row['connection_status'] === 'online') $summary['online']++;
            if ($row['connection_status'] === 'stale') $summary['stale']++;
            if ($row['installation_status'] === 'not_provisioned') $summary['not_provisioned']++;
            $summary['resident_count'] += (int) $row['resident_count'];
            $summary['catalog_count'] += (int) $row['catalog_count'];
            $summary['staff_count'] += (int) $row['staff_count'];
            $summary['citizen_account_count'] += (int) $row['citizen_account_count'];
            $summary['request_total'] += (int) $row['request_total'];
            $summary['request_open'] += (int) $row['request_open'];
            $summary['pending_inbound'] += (int) $row['pending_inbound'];
            $summary['pending_outbound'] += (int) $row['pending_outbound'];
            $summary['failed_messages'] += (int) $row['failed_messages'];
        }
        $summary['matched_regencies'] = count($regencies);
        return $summary;
    }

    private function selector_row_key(array $row, array $selectors)
    {
        foreach ($selectors as $index => $selector) {
            if ($this->tenant_matches_selector($row, $selector)) {
                return 'selector:' . (int) $index;
            }
        }
        return $this->region_key(
            isset($row['regency_code']) ? $row['regency_code'] : '',
            isset($row['regency_name']) ? $row['regency_name'] : ''
        );
    }

    private function build_regency_summary(array $rows, array $selectors)
    {
        $groups = array();
        $aliases = array();
        foreach ($selectors as $index => $selector) {
            $key = 'selector:' . (int) $index;
            $groups[$key] = array(
                'code' => isset($selector['code']) ? $selector['code'] : '',
                'name' => isset($selector['name']) ? $selector['name'] : '',
                'villages' => 0,
                'installed' => 0,
                'online' => 0,
                'stale' => 0,
                'not_provisioned' => 0,
                'resident_count' => 0,
                'catalog_count' => 0,
                'staff_count' => 0,
                'citizen_account_count' => 0,
                'request_total' => 0,
                'pending' => 0,
                'failed' => 0,
                'last_seen_at' => ''
            );
            foreach ((array) (isset($selector['codes']) ? $selector['codes'] : array()) as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code !== '') $aliases['code:' . $code] = $key;
            }
            $nameKey = $this->region_name_key(isset($selector['name']) ? $selector['name'] : '');
            if ($nameKey !== '') $aliases['name:' . $nameKey] = $key;
        }
        foreach ($rows as $row) {
            $codeAlias = 'code:' . strtoupper(trim((string) $row['regency_code']));
            $nameAlias = 'name:' . $this->region_name_key($row['regency_name']);
            $key = isset($aliases[$codeAlias]) ? $aliases[$codeAlias] : (isset($aliases[$nameAlias]) ? $aliases[$nameAlias] : $this->region_key($row['regency_code'], $row['regency_name']));
            if (!isset($groups[$key])) {
                $groups[$key] = array('code' => $row['regency_code'], 'name' => $row['regency_name'], 'villages' => 0, 'installed' => 0, 'online' => 0, 'stale' => 0, 'not_provisioned' => 0, 'resident_count' => 0, 'catalog_count' => 0, 'staff_count' => 0, 'citizen_account_count' => 0, 'request_total' => 0, 'pending' => 0, 'failed' => 0, 'last_seen_at' => '');
            }
            $groups[$key]['villages']++;
            if ($row['installation_status'] === 'active' || $row['installation_status'] === 'multiple_active') $groups[$key]['installed']++;
            if ($row['connection_status'] === 'online') $groups[$key]['online']++;
            if ($row['connection_status'] === 'stale') $groups[$key]['stale']++;
            if ($row['installation_status'] === 'not_provisioned') $groups[$key]['not_provisioned']++;
            $groups[$key]['resident_count'] += (int) $row['resident_count'];
            $groups[$key]['catalog_count'] += (int) $row['catalog_count'];
            $groups[$key]['staff_count'] += (int) $row['staff_count'];
            $groups[$key]['citizen_account_count'] += (int) $row['citizen_account_count'];
            $groups[$key]['request_total'] += (int) $row['request_total'];
            $groups[$key]['pending'] += (int) $row['pending_inbound'] + (int) $row['pending_outbound'];
            $groups[$key]['failed'] += (int) $row['failed_messages'];
            if (strtotime((string) $row['last_seen_at']) > strtotime((string) $groups[$key]['last_seen_at'])) $groups[$key]['last_seen_at'] = $row['last_seen_at'];
        }
        $result = array_values($groups);
        usort($result, function ($a, $b) { return strcasecmp((string) $a['name'], (string) $b['name']); });
        return $result;
    }

    private function normalise_selectors($raw)
    {
        if (!is_array($raw)) return array();
        $result = array();
        $seen = array();
        foreach ($raw as $item) {
            $selector = $this->normalise_selector($item);
            if ($selector === NULL) continue;
            $codeFamilies = array();
            foreach ((array) $selector['codes'] as $code) {
                $family = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $code));
                if ($family !== '') $codeFamilies[$family] = TRUE;
            }
            ksort($codeFamilies, SORT_STRING);
            $key = $this->region_name_key($selector['name']) . '|code|' . implode(',', array_keys($codeFamilies));
            if (isset($seen[$key])) continue;
            $seen[$key] = TRUE;
            $result[] = $selector;
            if (count($result) >= 500) break;
        }
        return $result;
    }

    private function normalise_selector($item)
    {
        if (is_scalar($item)) $item = array('code' => (string) $item, 'name' => '');
        if (!is_array($item)) return NULL;
        $codes = array();
        $addCode = function ($value) use (&$codes) {
            $code = strtoupper(trim((string) $value));
            if ($code !== '' && preg_match('/^[A-Z0-9._-]{2,40}$/', $code) && !in_array($code, $codes, TRUE)) {
                $codes[] = $code;
            }
        };
        foreach (array('code', 'alternate_code') as $field) {
            if (isset($item[$field])) $addCode($item[$field]);
        }
        if (isset($item['codes']) && is_array($item['codes'])) {
            foreach ($item['codes'] as $value) {
                $addCode($value);
            }
        }
        $name = $this->clean_text(isset($item['name']) ? $item['name'] : '', 120);
        if (empty($codes) && $this->region_name_key($name) === '') return NULL;
        return array('code' => !empty($codes) ? $codes[0] : '', 'codes' => $codes, 'name' => $name);
    }

    private function selector_list_matches(array $selectors, array $selected)
    {
        foreach ($selectors as $selector) {
            if ($this->selector_matches_selector($selector, $selected)) return TRUE;
        }
        return FALSE;
    }

    private function selector_matches_selector(array $left, array $right)
    {
        foreach ((array) $left['codes'] as $code) if (in_array($code, (array) $right['codes'], TRUE)) return TRUE;
        return $this->region_name_key($left['name']) !== '' && $this->region_name_key($left['name']) === $this->region_name_key($right['name']);
    }

    private function tenant_matches_selector(array $tenant, array $selector)
    {
        $code = strtoupper(trim((string) (isset($tenant['regency_code']) ? $tenant['regency_code'] : '')));
        if ($code !== '' && in_array($code, (array) $selector['codes'], TRUE)) return TRUE;
        $name = $this->region_name_key(isset($tenant['regency_name']) ? $tenant['regency_name'] : '');
        return $name !== '' && $name === $this->region_name_key($selector['name']);
    }

    private function region_key($code, $name)
    {
        $code = strtoupper(trim((string) $code));
        return $code !== '' ? 'code:' . $code : 'name:' . $this->region_name_key($name);
    }

    private function region_name_key($value)
    {
        $value = $this->normalise_name($value);
        $value = preg_replace('/^(kabupaten|kab\.?|kota administrasi|kota|adm\.?)\s+/u', '', $value);
        return trim((string) $value);
    }

    private function clean_text($value, $max)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        if (function_exists('mb_substr')) return mb_substr($value, 0, (int) $max, 'UTF-8');
        return substr($value, 0, (int) $max);
    }

    private function normalise_name($value)
    {
        $value = $this->clean_text($value, 200);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function mask_code($value)
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        $length = strlen($value);
        if ($length <= 8) return str_repeat('*', $length);
        return substr($value, 0, 5) . str_repeat('*', max(4, $length - 8)) . substr($value, -3);
    }

    private function table_ready($table)
    {
        if (!isset($this->tableCache[$table])) {
            // CI_Model exposes the database through __get(), so isset($this->db)
            // is false even when the connection is available.
            $db = $this->db;
            $this->tableCache[$table] = is_object($db) && $db->table_exists($table);
        }
        return $this->tableCache[$table];
    }

    private function table_fields($table)
    {
        return $this->table_ready($table) ? $this->db->list_fields($table) : array();
    }
}
