<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Sync_model extends CI_Model
{
    public function document_for_installation(array $installation, $documentId)
    {
        if (getenv('API_DEMO_MODE') === '1' || empty($installation['village_id'])) return NULL;

        return $this->db
            ->select('d.id, d.original_name, d.storage_path, d.mime_type, d.file_size, d.request_id')
            ->from('request_documents d')
            ->join('service_requests r', 'r.id=d.request_id')
            ->where(array(
                'd.id' => (string) $documentId,
                'r.village_id' => (string) $installation['village_id']
            ))
            ->limit(1)
            ->get()
            ->row_array();
    }

    public function pull(array $installation, $limit = 50)
    {
        $limit = max(1, min(100, (int) $limit));
        if (getenv('API_DEMO_MODE') === '1') return array(
            array('id' => 'demo-sync-0001', 'aggregate_type' => 'service_request', 'aggregate_id' => 'demo-request-000000000000000000000000000003', 'operation' => 'upsert', 'payload' => array('request_code' => 'SDW-2026-0003', 'status' => 'submitted', 'service_slug' => 'usaha', 'citizen_name' => 'Mabel Wenda'), 'attempts' => 1)
        );
        $this->db->where('expires_at <', date('Y-m-d H:i:s'))->delete('api_request_nonces');
        $this->db->where('village_id', $installation['village_id'])->where('direction', 'cloud_to_local');
        $this->db->group_start()->where('status', 'pending')->or_group_start()->where('status', 'processing')->where('updated_at <', date('Y-m-d H:i:s', time() - 600))->group_end()->group_end();
        $rows = $this->db->order_by('available_at', 'ASC')->order_by('created_at', 'ASC')->limit($limit)->get('sync_messages')->result_array();
        $result = array();
        foreach ($rows as $row) {
            $this->db->set('attempts', 'attempts + 1', FALSE)->set('status', 'processing')->set('installation_id', $installation['id'])->where(array('id' => $row['id'], 'village_id' => $installation['village_id'], 'status' => $row['status']));
            if ($row['status'] === 'processing') $this->db->where('updated_at <', date('Y-m-d H:i:s', time() - 600));
            $this->db->update('sync_messages');
            if ($this->db->affected_rows() < 1) continue;
            $payload = json_decode((string) $row['payload_json'], TRUE);
            if (!is_array($payload)) $payload = array();
            $result[] = array('id' => $row['id'], 'aggregate_type' => $row['aggregate_type'], 'aggregate_id' => $row['aggregate_id'], 'operation' => $row['operation'], 'payload' => $payload, 'attempts' => ((int) $row['attempts']) + 1);
        }
        return $result;
    }

    public function acknowledge(array $installation, array $messages)
    {
        if (getenv('API_DEMO_MODE') === '1') return count($messages);
        $processed = 0;
        foreach ($messages as $message) {
            if (!is_array($message) || !preg_match('/^[A-Za-z0-9._:-]{3,180}$/', (string) ($message['id'] ?? ''))) continue;
            $state = isset($message['status']) && $message['status'] === 'failed' ? 'failed' : 'processed';
            $error = isset($message['error']) ? substr(trim((string) $message['error']), 0, 1000) : NULL;
            $this->db->where(array('id' => (string) $message['id'], 'village_id' => $installation['village_id'], 'installation_id' => $installation['id']))->where_in('status', array('pending', 'processing'))->update('sync_messages', array('status' => $state, 'processed_at' => date('Y-m-d H:i:s'), 'last_error' => $error));
            $processed += $this->db->affected_rows() > 0 ? 1 : 0;
        }
        return $processed;
    }

    public function enqueue(array $installation, array $messages)
    {
        if (getenv('API_DEMO_MODE') === '1') {
            return array('accepted' => count($messages), 'rejected' => 0, 'results' => array());
        }

        $accepted = 0;
        $rejected = 0;
        $results = array();

        foreach ($messages as $message) {
            if (!is_array($message)) {
                $rejected++;
                continue;
            }

            $idempotency = trim((string) ($message['idempotency_key'] ?? ''));
            $aggregateType = trim((string) ($message['aggregate_type'] ?? ''));
            $aggregateId = trim((string) ($message['aggregate_id'] ?? ''));
            $operation = trim((string) ($message['operation'] ?? ''));
            $payload = isset($message['payload']) && is_array($message['payload']) ? $message['payload'] : NULL;

            if (!preg_match('/^[A-Za-z0-9._:-]{8,180}$/', $idempotency)
                || !preg_match('/^[A-Za-z0-9._:-]{2,80}$/', $aggregateType)
                || $aggregateId === ''
                || strlen($aggregateId) > 100
                || !preg_match('/^[A-Za-z0-9._:-]{2,40}$/', $operation)
                || $payload === NULL
            ) {
                $rejected++;
                continue;
            }

            $existing = $this->db->select('id')->where('idempotency_key', $idempotency)->get('sync_messages')->row_array();
            if ($existing) {
                $accepted++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'duplicate');
                continue;
            }

            $this->db->trans_begin();
            $messageId = api_uuid();
            $inserted = $this->db->insert('sync_messages', array(
                'id' => $messageId,
                'village_id' => $installation['village_id'],
                'installation_id' => $installation['id'],
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'direction' => 'local_to_cloud',
                'operation' => $operation,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'idempotency_key' => $idempotency
            ));

            if (!$inserted) {
                $this->db->trans_rollback();
                $rejected++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Pesan duplikat atau belum dapat disimpan.');
                continue;
            }

            $apply = array('success' => TRUE, 'message' => 'Pesan diterima.');
            if ($aggregateType === 'service_request' && $operation === 'status_update') {
                $apply = $this->apply_local_status_update($installation, $aggregateId, $payload);
            }

            if (empty($apply['success']) || !$this->db->trans_status()) {
                $this->db->trans_rollback();
                $rejected++;
                $results[] = array(
                    'idempotency_key' => $idempotency,
                    'status' => 'failed',
                    'message' => isset($apply['message']) ? $apply['message'] : 'Perubahan belum dapat diterapkan.'
                );
                continue;
            }

            $this->db->where('id', $messageId)->update('sync_messages', array(
                'status' => 'processed',
                'processed_at' => date('Y-m-d H:i:s')
            ));
            if (!$this->db->trans_status()) {
                $this->db->trans_rollback();
                $rejected++;
                $results[] = array('idempotency_key' => $idempotency, 'status' => 'failed', 'message' => 'Riwayat sinkronisasi belum dapat disimpan.');
                continue;
            }

            $this->db->trans_commit();
            $accepted++;
            $results[] = array(
                'idempotency_key' => $idempotency,
                'status' => 'accepted',
                'message' => isset($apply['message']) ? $apply['message'] : 'Perubahan diterapkan.'
            );
        }

        return array('accepted' => $accepted, 'rejected' => $rejected, 'results' => $results);
    }

    private function apply_local_status_update(array $installation, $aggregateId, array $payload)
    {
        $request = $this->db
            ->where(array('id' => (string) $aggregateId, 'village_id' => $installation['village_id']))
            ->limit(1)
            ->get('service_requests')
            ->row_array();
        if (!$request) {
            return array('success' => FALSE, 'message' => 'Permohonan tidak ditemukan pada desa ini.');
        }

        $fromStatus = trim((string) $request['status']);
        $toStatus = trim((string) (isset($payload['status']) ? $payload['status'] : (isset($payload['to_status']) ? $payload['to_status'] : '')));
        $actorRole = strtolower(trim((string) (isset($payload['actor_role']) ? $payload['actor_role'] : '')));
        $actorRole = str_replace('_', '-', $actorRole);
        $note = trim((string) (isset($payload['note']) ? $payload['note'] : ''));
        $actorName = substr(trim((string) (isset($payload['actor_name']) ? $payload['actor_name'] : 'Petugas Desa')), 0, 160);

        $allowed = FALSE;
        if ($actorRole === 'sekdes') {
            $allowed = $fromStatus === 'submitted' && in_array($toStatus, array('verified', 'revision', 'rejected'), TRUE);
        } elseif (in_array($actorRole, array('kepala-desa', 'kades'), TRUE)) {
            $allowed = $fromStatus === 'verified' && in_array($toStatus, array('approved', 'revision', 'rejected'), TRUE);
        } elseif (in_array($actorRole, array('admin-desa', 'administrator', 'admin-pusat'), TRUE)) {
            $allowed = ($fromStatus === 'submitted' && in_array($toStatus, array('verified', 'revision', 'rejected'), TRUE))
                || ($fromStatus === 'verified' && in_array($toStatus, array('approved', 'revision', 'rejected'), TRUE))
                || ($fromStatus === 'approved' && in_array($toStatus, array('issued', 'revision', 'rejected'), TRUE));
        }

        if (!$allowed) {
            return array('success' => FALSE, 'message' => 'Perubahan status tidak sesuai alur atau peran petugas.');
        }
        if (in_array($toStatus, array('revision', 'rejected'), TRUE) && $note === '') {
            return array('success' => FALSE, 'message' => 'Alasan wajib diisi untuk perbaikan atau penolakan.');
        }
        if ($note === '') {
            $note = 'Status diperbarui melalui SmartDesa lokal.';
        }

        $now = date('Y-m-d H:i:s');
        $this->db
            ->where(array('id' => (string) $aggregateId, 'village_id' => $installation['village_id'], 'status' => $fromStatus))
            ->update('service_requests', array(
                'status' => $toStatus,
                'local_sync_status' => 'synced',
                'local_synced_at' => $now
            ));
        if ($this->db->affected_rows() !== 1) {
            return array('success' => FALSE, 'message' => 'Status permohonan berubah karena diproses pengguna lain.');
        }

        $this->db->insert('request_status_history', array(
            'request_id' => (string) $aggregateId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'actor_id' => NULL,
            'occurred_at' => $now
        ));
        $serviceName = !empty($request['service_type_id']) ? 'Permohonan layanan' : 'Permohonan warga';
        $this->db->insert('notifications', array(
            'id' => api_uuid(),
            'user_id' => (int) $request['citizen_user_id'],
            'request_id' => (string) $aggregateId,
            'title' => $serviceName,
            'message' => $toStatus . ' oleh ' . ($actorName !== '' ? $actorName : 'petugas desa') . '. ' . $note
        ));

        return array('success' => TRUE, 'message' => 'Status permohonan diperbarui menjadi ' . $toStatus . '.');
    }
}
