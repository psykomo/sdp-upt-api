<?php

namespace App\Modules\Queue\Controllers;

use App\Controllers\BaseController;
use App\Modules\Queue\Services\QueueService;
use App\Modules\Shared\Http\ApiResponse;
use CodeIgniter\HTTP\ResponseInterface;

final class QueueController extends BaseController
{
    private function service(): QueueService
    {
        return new QueueService();
    }

    /**
     * Upload a consolidation file (zip) and push it to the queue.
     * This endpoint is called by 102sdp (UPT).
     */
    public function upload(): ResponseInterface
    {
        // Require API/Service Key validation here, or rely on a Filter
        $authHeader = $this->request->getHeaderLine('X-SDP-Service-Key');
        // Simple mock check for now. Should match a configured secret.
        if (empty($authHeader) && env('CI_ENVIRONMENT') !== 'development') {
            return ApiResponse::fail("Unauthorized service key.", 401);
        }

        $satkerId      = $this->request->getPost('satker_id') ?: null;
        $dataType      = $this->request->getPost('data_type') ?? 'data';
        $priority      = (int) ($this->request->getPost('priority') ?? 0);
        $fileNameParam = $this->request->getPost('file_name');

        // Check if satkerId exists in upt table for foreign key constraint
        $idUpt = null;
        if ($satkerId !== null) {
            $db = \Config\Database::connect();
            if ($db->table('upt')->where('ID_UPT', $satkerId)->countAllResults() > 0) {
                $idUpt = $satkerId;
            }
        }
        
        $uploadPath = WRITEPATH . 'uploads/konsolidasi';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $filePrefix = ($idUpt ?? $satkerId ?? 'upt') . '_' . date('YmdHis') . '_';

        $file = $this->request->getFile('file');
        if ($file && $file->isValid()) {
            // Direct API Upload
            $newName = $filePrefix . $file->getRandomName();
            $file->move($uploadPath, $newName);
            $savedPath = $uploadPath . '/' . $newName;
            $originalName = $file->getClientName();
        } elseif (!empty($fileNameParam)) {
            // Legacy FTP Upload Support
            // Asumsi file diunggah via FTP ke direktori kerja SDP Pusat atau direktori FTP UPT
            // (Dalam dev environment, biasanya di root direktori htdocs/sdp_pusat atau serupa, kita mock mencari file tersebut)
            // Gunakan path tunggal yang pasti untuk FTP upload, disamakan dengan direktori temporary/konsolidasi sdp_pusat
            $rawFtpPath = trim(env('FTP_UPLOAD_PATH') ?? '', " \t\n\r\0\x0B\"'");
            $ftpDropDir = realpath($rawFtpPath);
            if (!$ftpDropDir || !is_dir($ftpDropDir)) {
                return ApiResponse::fail("Direktori FTP_UPLOAD_PATH tidak valid atau tidak ditemukan.", 500);
            }
            
            // Pastikan aman untuk Windows dan Linux (hindari traversal dan normalisasi slash)
            $cleanFileName = basename(str_replace('\\', '/', $fileNameParam));
            $foundFtpPath = rtrim($ftpDropDir, '/\\') . DIRECTORY_SEPARATOR . $cleanFileName;

            if (!file_exists($foundFtpPath)) {
                return ApiResponse::fail("File FTP tidak ditemukan: {$cleanFileName}", 404);
            }

            $newName = $filePrefix . $cleanFileName;
            copy($foundFtpPath, $uploadPath . '/' . $newName);
            $savedPath = $uploadPath . '/' . $newName;
            $originalName = $cleanFileName;
        } else {
            return ApiResponse::fail("No valid file uploaded or file_name provided.", 400);
        }

        // Push to queue with specific data type (data, dokumen, image, sidik_jari, daf)
        $jobType = !empty($dataType) ? strtolower($dataType) : 'data';
        $payload = [
            'file_path'     => $savedPath,
            'satker_id'     => $satkerId,
            'data_type'     => $jobType,
            'original_name' => $originalName
        ];
        $jobId = $this->service()->push($idUpt, $jobType, $payload, $priority);

        return ApiResponse::ok([
            'message' => 'File received and queued successfully.',
            'job_id'  => $jobId
        ]);
    }

    /**
     * Get the status and progress of a queue job.
     */
    public function status(string $id): ResponseInterface
    {
        $status = $this->service()->status($id);
        
        if (!$status) {
            return ApiResponse::fail("Job not found", 404);
        }
        
        return ApiResponse::ok($status);
    }

    /**
     * POST /api/v1/queue/status
     * Bulk status check for multiple jobs.
     * Body: { "job_ids": ["uuid1", "uuid2", ...] }
     *
     * Returns formatted queue numbers and global processing position.
     */
    public function bulkStatus(): ResponseInterface
    {
        $input = null;
        try {
            $input = $this->request->getJSON(true);
        } catch (\Throwable $e) {
            $input = null;
        }

        $jobIds   = $input['job_ids'] ?? $this->request->getPost('job_ids') ?? [];
        $satkerId = $input['satker_id'] ?? $this->request->getPost('satker_id') ?? $this->request->getGet('satker_id');

        if (is_string($jobIds)) {
            $decoded = json_decode($jobIds, true);
            if (is_array($decoded)) {
                $jobIds = $decoded;
            } else {
                $jobIds = array_filter(array_map('trim', explode(',', $jobIds)));
            }
        }

        if ((!is_array($jobIds) || empty($jobIds)) && empty($satkerId)) {
            return ApiResponse::fail("Parameter job_ids (array) atau satker_id diperlukan.", 400);
        }

        if (!is_array($jobIds)) {
            $jobIds = [];
        }

        // Cap at 50 to prevent abuse
        $jobIds = array_slice($jobIds, 0, 50);

        $result = $this->service()->bulkStatus($jobIds, !empty($satkerId) ? (string) $satkerId : null);

        return ApiResponse::ok($result);
    }

    /**
     * GET /api/v1/konsolidasi/waktu
     *
     * Mengembalikan waktu server CI4 Pusat saat ini.
     * Digunakan oleh UPT untuk sinkronisasi / validasi selisih waktu
     * sebelum melakukan konsolidasi.
     *
     * Respons kompatibel dengan format legacy CI2 (status + message):
     *   { "status": true, "server_time": "2026-09-07 17:35:00", "message": "" }
     *
     * Status false jika UPT mengirimkan waktu yang lebih dari THRESHOLD menit
     * lebih awal/akhir dari server Pusat (kemungkinan jam server UPT dimundurkan).
     */
    public function waktu(): ResponseInterface
    {
        $authHeader = $this->request->getHeaderLine('X-SDP-Service-Key');
        if (empty($authHeader) && env('CI_ENVIRONMENT') !== 'development') {
            return ApiResponse::fail("Unauthorized service key.", 401);
        }

        $serverTimestamp = time();
        $serverTime      = date('Y-m-d H:i:s', $serverTimestamp);

        $tsClient = $this->request->getGet('ts_client');
        $message  = '';
        $status   = true;

        if (!empty($tsClient) && is_numeric($tsClient)) {
            // Bandingkan Unix timestamp langsung — bebas dari masalah timezone
            $diff = abs($serverTimestamp - (int) $tsClient);

            // Toleransi selisih waktu maksimum: 10 menit
            $thresholdSeconds = 10 * 60;
            if ($diff > $thresholdSeconds) {
                $status  = false;
                $message = ' Selisih waktu UPT dengan Pusat: ' . round($diff / 60, 1) . ' menit. Toleransi maksimal 10 menit.';
            }
        }

        return $this->response->setStatusCode(200)->setJSON([
            'status'      => $status,
            'server_time' => $serverTime,
            'message'     => $message,
        ]);
    }
}
