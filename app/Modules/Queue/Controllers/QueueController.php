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

        $satkerId = $this->request->getPost('satker_id') ?? 'UNKNOWN';
        $dataType = $this->request->getPost('data_type') ?? 'data';
        $fileNameParam = $this->request->getPost('file_name');
        
        $uploadPath = WRITEPATH . 'uploads/konsolidasi';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $file = $this->request->getFile('file');
        if ($file && $file->isValid()) {
            // Direct API Upload
            $newName = $satkerId . '_' . date('YmdHis') . '_' . $file->getRandomName();
            $file->move($uploadPath, $newName);
            $savedPath = $uploadPath . '/' . $newName;
            $originalName = $file->getClientName();
        } elseif (!empty($fileNameParam)) {
            // Legacy FTP Upload Support
            // Asumsi file diunggah via FTP ke direktori kerja SDP Pusat atau direktori FTP UPT
            // (Dalam dev environment, biasanya di root direktori htdocs/sdp_pusat atau serupa, kita mock mencari file tersebut)
            // Gunakan path tunggal yang pasti untuk FTP upload, disamakan dengan direktori temporary/konsolidasi sdp_pusat
            $ftpDropDir = realpath(env('FTP_UPLOAD_PATH') ?? '');
            if (!$ftpDropDir || !is_dir($ftpDropDir)) {
                return ApiResponse::fail("Direktori FTP_UPLOAD_PATH tidak valid atau tidak ditemukan.", 500);
            }
            $foundFtpPath = $ftpDropDir . DIRECTORY_SEPARATOR . $fileNameParam;

            if (!file_exists($foundFtpPath)) {
                return ApiResponse::fail("File FTP tidak ditemukan: {$fileNameParam}", 404);
            }

            $newName = $satkerId . '_' . date('YmdHis') . '_' . $fileNameParam;
            copy($foundFtpPath, $uploadPath . '/' . $newName);
            $savedPath = $uploadPath . '/' . $newName;
            $originalName = $fileNameParam;
        } else {
            return ApiResponse::fail("No valid file uploaded or file_name provided.", 400);
        }

        // Push to queue
        $payload = [
            'file_path' => $savedPath,
            'satker_id' => $satkerId,
            'data_type' => $dataType,
            'original_name' => $file->getClientName()
        ];
        
        $jobId = $this->service()->push('konsolidasi', $payload);

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
}
