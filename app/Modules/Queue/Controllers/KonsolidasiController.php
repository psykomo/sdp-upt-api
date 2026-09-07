<?php

namespace App\Modules\Queue\Controllers;

use App\Controllers\BaseController;
use App\Modules\Queue\Services\KonsolidasiPusatService;
use App\Modules\Shared\Http\ApiResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Controller yang mereplikasi endpoint CI2 changescompress di sisi Pusat.
 *
 * Endpoint ini dipanggil oleh:
 *   - 102sdp (UPT) melalui readUPTChangesFromServer()
 *   - sdp-upt-api lokal UPT (apabila ada proxy layer)
 *
 * Format respons harus kompatibel dengan parsing di Konsolidasi.php (102sdp):
 *   - HTTP 200 + JSON: { "<data_type>": { file_data: "<base64>", table_backup_result: {...} } }
 *   - HTTP 404 + { error: "..." } jika tidak ada perubahan
 *
 * Auth: X-SDP-Service-Key header (sama dengan upload endpoint).
 */
final class KonsolidasiController extends BaseController
{
    private function service(): KonsolidasiPusatService
    {
        return new KonsolidasiPusatService();
    }

    /**
     * GET /api/v1/konsolidasi/changes/upt/{id}?request_data_type=data|dokumen
     */
    public function changesUpt(string $idUpt): ResponseInterface
    {
        $authError = $this->checkServiceKey();
        if ($authError !== null) {
            return $authError;
        }

        $types = $this->parseDataTypes($this->request->getGet('request_data_type'));

        try {
            $result    = $this->service()->getChangesForUpt($idUpt, $types);
            $totalRows = $this->countTotalRows($result);

            if ($totalRows > 0) {
                return $this->response->setStatusCode(200)->setJSON($result);
            }

            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Tidak dapat ditemukan perubahan pada server untuk UPT ini!',
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[KonsolidasiController] changesUpt error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/konsolidasi/changes/kanwil/{id}?request_data_type=data|dokumen
     */
    public function changesKanwil(string $idKanwil): ResponseInterface
    {
        $authError = $this->checkServiceKey();
        if ($authError !== null) {
            return $authError;
        }

        $types = $this->parseDataTypes($this->request->getGet('request_data_type'));

        try {
            $result    = $this->service()->getChangesForKanwil($idKanwil, $types);
            $totalRows = $this->countTotalRows($result);

            if ($totalRows > 0) {
                return $this->response->setStatusCode(200)->setJSON($result);
            }

            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Tidak dapat ditemukan perubahan pada server untuk Kanwil ini!',
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[KonsolidasiController] changesKanwil error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/v1/konsolidasi/changes/upt_readed/{id}/{table_name}
     * Body: for_download_target_temp_path (optional)
     */
    public function uptReaded(string $idUpt, string $tableName): ResponseInterface
    {
        $authError = $this->checkServiceKey();
        if ($authError !== null) {
            return $authError;
        }

        $tempPath = $this->request->getPost('for_download_target_temp_path') ?? '';
        $this->service()->markReadedUpt($idUpt, urldecode($tableName), $tempPath);

        return $this->response->setStatusCode(200)->setJSON(true);
    }

    /**
     * POST /api/v1/konsolidasi/changes/kanwil_readed/{id}/{table_name}
     */
    public function kanwilReaded(string $idKanwil, string $tableName): ResponseInterface
    {
        $authError = $this->checkServiceKey();
        if ($authError !== null) {
            return $authError;
        }

        $tempPath = $this->request->getPost('for_download_target_temp_path') ?? '';
        $this->service()->markReadedKanwil($idKanwil, urldecode($tableName), $tempPath);

        return $this->response->setStatusCode(200)->setJSON(true);
    }

    /**
     * POST /api/v1/konsolidasi/changes/upt_readed_clear_temp/{id}
     */
    public function uptClearTemp(string $idUpt): ResponseInterface
    {
        $authError = $this->checkServiceKey();
        if ($authError !== null) {
            return $authError;
        }

        $tempPath = $this->request->getPost('for_download_target_temp_path') ?? '';
        $this->service()->clearTemp($tempPath);

        return $this->response->setStatusCode(200)->setJSON(true);
    }

    /**
     * POST /api/v1/konsolidasi/changes/kanwil_readed_clear_temp/{id}
     */
    public function kanwilClearTemp(string $idKanwil): ResponseInterface
    {
        $authError = $this->checkServiceKey();
        if ($authError !== null) {
            return $authError;
        }

        $tempPath = $this->request->getPost('for_download_target_temp_path') ?? '';
        $this->service()->clearTemp($tempPath);

        return $this->response->setStatusCode(200)->setJSON(true);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Cek service key. Return null jika valid, ResponseInterface jika error.
     */
    private function checkServiceKey(): ?ResponseInterface
    {
        $key = $this->request->getHeaderLine('X-SDP-Service-Key');
        if (empty($key) && env('CI_ENVIRONMENT') !== 'development') {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized service key.']);
        }
        return null;
    }

    /**
     * Parse request_data_type "data|dokumen" menjadi array ['data', 'dokumen'].
     */
    private function parseDataTypes(?string $raw): array
    {
        if (empty($raw)) {
            return ['data', 'dokumen'];
        }
        return array_filter(explode('|', $raw));
    }

    /**
     * Hitung total baris dari semua data_type dalam result.
     */
    private function countTotalRows(array $result): int
    {
        $total = 0;
        foreach ($result as $typeData) {
            if (isset($typeData['num_rows'])) {
                $total += (int) $typeData['num_rows'];
            } elseif (!empty($typeData['file_data'])) {
                $total += 1; // ada data
            }
        }
        return $total;
    }
}
