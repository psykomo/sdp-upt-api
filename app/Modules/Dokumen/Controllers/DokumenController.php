<?php

namespace App\Modules\Dokumen\Controllers;

use App\Controllers\BaseController;
use App\Modules\Dokumen\Services\DokumenException;
use App\Modules\Dokumen\Services\DokumenService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;

final class DokumenController extends BaseController
{
    public function download(string $idDokumen)
    {
        return $this->stream($idDokumen, false);
    }

    public function view(string $idDokumen)
    {
        return $this->stream($idDokumen, true);
    }

    public function update(string $idDokumen)
    {
        $file = $this->request->getFile('file');
        $json = $this->request->getJSON(true);
        if (! is_array($json)) {
            $json = [];
        }

        try {
            $result = (new DokumenService())->updateDocument(
                $idDokumen,
                AuthContext::user(),
                $this->request->getPost('judul') ?? ($json['judul'] ?? null),
                $this->request->getPost('keterangan') ?? ($json['keterangan'] ?? null),
                $file && $file->isValid() ? (string) $file->getClientName() : null,
                $file && $file->isValid() ? (string) $file->getClientMimeType() : null,
                $file && $file->isValid() ? (string) file_get_contents($file->getTempName()) : null,
            );
        } catch (DokumenException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok($result);
    }

    public function destroy(string $idDokumen)
    {
        try {
            (new DokumenService())->deleteDocument($idDokumen, AuthContext::user());
        } catch (DokumenException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok(['ok' => true]);
    }

    private function stream(string $idDokumen, bool $inline)
    {
        try {
            $file = (new DokumenService())->streamFile($idDokumen, AuthContext::user());
        } catch (DokumenException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return $this->response
            ->setHeader('Content-Type', $file['mimeType'])
            ->setHeader(
                'Content-Disposition',
                ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($file['fileName']) . '"',
            )
            ->setBody(file_get_contents($file['path']) ?: '');
    }
}
