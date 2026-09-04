<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Penerbitan PDF resmi dari instalasi SmartDesa lokal.
 */
class Requests extends MY_Controller
{
    public function official_document($requestId = '')
    {
        if (!$this->require_method('POST')) return;
        $installation = $this->authenticate_installation();
        if (!$installation) return;

        $requestId = strtolower(rawurldecode(trim((string) $requestId)));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $requestId)) {
            return $this->fail('Permohonan tidak ditemukan.', 404, 'not_found');
        }
        $maxBytes = max(1024, min(16 * 1024 * 1024, (int) (getenv('API_OFFICIAL_DOCUMENT_MAX_BYTES') ?: 8388608)));
        $size = strlen($this->rawBody);
        $sha256 = strtolower(trim(api_header('X-SmartDesa-Document-SHA256')));
        if ($size < 5 || $size > $maxBytes || strncmp($this->rawBody, '%PDF-', 5) !== 0) {
            return $this->fail('Berkas harus berupa PDF resmi dengan ukuran yang diizinkan.', 415, 'invalid_document');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256) || !hash_equals($sha256, hash('sha256', $this->rawBody))) {
            return $this->fail('Hash dokumen resmi tidak sesuai.', 422, 'invalid_document_hash');
        }

        $reference = $this->decode_header_value('X-SmartDesa-Document-Reference', 160);
        $filename = $this->safe_pdf_name($this->decode_header_value('X-SmartDesa-Document-Name', 180), $requestId);
        $actorName = $this->decode_header_value('X-SmartDesa-Actor-Name', 160);
        if ($reference === '') return $this->fail('Nomor surat resmi belum tersedia.', 422, 'missing_reference');

        $root = realpath(trim((string) getenv('PRIVATE_STORAGE_PATH')));
        if ($root === FALSE || !is_dir($root) || !is_writable($root)) {
            return $this->fail('Penyimpanan dokumen resmi belum siap.', 503, 'storage_unavailable');
        }
        $villageId = preg_replace('/[^a-f0-9-]/i', '', (string) $installation['village_id']);
        $directory = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'official-documents' . DIRECTORY_SEPARATOR . $villageId . DIRECTORY_SEPARATOR . $requestId;
        if ((!is_dir($directory) && !@mkdir($directory, 0700, TRUE)) || !is_writable($directory)) {
            return $this->fail('Folder dokumen resmi belum dapat dibuat.', 503, 'storage_unavailable');
        }
        $realDirectory = realpath($directory);
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($realDirectory === FALSE || strpos($realDirectory . DIRECTORY_SEPARATOR, $rootPrefix) !== 0) {
            return $this->fail('Lokasi dokumen resmi tidak valid.', 503, 'storage_unavailable');
        }

        $lock = @fopen($realDirectory . DIRECTORY_SEPARATOR . '.publish.lock', 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return $this->fail('Dokumen sedang diterbitkan. Coba kembali sebentar lagi.', 425, 'document_busy');
        }
        try {
            return $this->store_official_document($installation, $requestId, $reference, $filename, $actorName, $sha256, $size, $realDirectory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function store_official_document($installation, $requestId, $reference, $filename, $actorName, $sha256, $size, $realDirectory)
    {
        $target = $realDirectory . DIRECTORY_SEPARATOR . $requestId . '-' . substr($sha256, 0, 16) . '.pdf';
        if (is_link($target)) return $this->fail('Lokasi dokumen resmi tidak valid.', 503, 'storage_unavailable');
        $targetExisted = is_file($target);
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
        $written = @file_put_contents($temporary, $this->rawBody, LOCK_EX);
        if ($written !== $size || !@chmod($temporary, 0600) || !@rename($temporary, $target)) {
            @unlink($temporary);
            return $this->fail('Dokumen resmi belum dapat disimpan.', 503, 'storage_write_failed');
        }

        $this->load->model('Sync_model');
        $result = $this->Sync_model->publish_official_document(
            $installation,
            $requestId,
            $reference,
            $target,
            $sha256,
            $size,
            $actorName
        );
        if (empty($result['success'])) {
            if (!$targetExisted) @unlink($target);
            return $this->fail(isset($result['message']) ? $result['message'] : 'Dokumen resmi belum dapat diterbitkan.', 409, 'document_not_published');
        }

        $this->touch_installation(TRUE);
        return $this->respond(array(
            'success' => TRUE,
            'service_request_id' => $requestId,
            'status' => 'issued',
            'reference' => $reference,
            'filename' => $filename,
            'sha256' => $sha256,
            'duplicate' => !empty($result['duplicate']),
            'message' => isset($result['message']) ? $result['message'] : 'Dokumen resmi berhasil diterbitkan.'
        ));
    }

    private function decode_header_value($name, $maxLength)
    {
        $encoded = trim(api_header($name));
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) return '';
        $padding = strlen($encoded) % 4;
        if ($padding > 0) $encoded .= str_repeat('=', 4 - $padding);
        $value = base64_decode(strtr($encoded, '-_', '+/'), TRUE);
        if (!is_string($value)) return '';
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        return function_exists('mb_substr')
            ? mb_substr($value, 0, (int) $maxLength, 'UTF-8')
            : substr($value, 0, (int) $maxLength);
    }

    private function safe_pdf_name($name, $requestId)
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '._-');
        if ($name === '') $name = 'surat-' . substr(str_replace('-', '', $requestId), 0, 12);
        if (!preg_match('/\.pdf$/i', $name)) $name .= '.pdf';
        return substr($name, 0, 180);
    }
}
