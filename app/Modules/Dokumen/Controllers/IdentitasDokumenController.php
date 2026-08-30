<?php

namespace App\Modules\Dokumen\Controllers;

use App\Controllers\BaseController;
use App\Modules\Dokumen\Services\DokumenException;
use App\Modules\Dokumen\Services\DokumenService;
use App\Modules\Identitas\Services\IdentitasAccessException;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;

final class IdentitasDokumenController extends BaseController
{
    public function index(string $nomorInduk)
    {
        try {
            $items = (new DokumenService())->listIdentitasDocuments($nomorInduk, AuthContext::user());
        } catch (IdentitasAccessException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok(['items' => $items]);
    }

    public function create(string $nomorInduk)
    {
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return ApiResponse::fail('Pilih berkas PDF.', 422, ['file' => 'Berkas wajib diisi.']);
        }

        try {
            $result = (new DokumenService())->createIdentitasDocument(
                $nomorInduk,
                AuthContext::user(),
                $this->request->getPost('judul'),
                $this->request->getPost('keterangan'),
                (string) $file->getClientName(),
                (string) $file->getClientMimeType(),
                (string) file_get_contents($file->getTempName()),
            );
        } catch (IdentitasAccessException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        } catch (DokumenException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok($result);
    }
}
