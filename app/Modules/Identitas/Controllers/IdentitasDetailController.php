<?php

namespace App\Modules\Identitas\Controllers;

use App\Controllers\BaseController;
use App\Modules\Identitas\Services\IdentitasDeleteException;
use App\Modules\Identitas\Services\IdentitasDeleteService;
use App\Modules\Identitas\Services\IdentitasDetailService;
use App\Modules\Identitas\Services\IdentitasFormService;
use App\Modules\Identitas\Services\IdentitasUpdateException;
use App\Modules\Identitas\Services\IdentitasUpdateService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;

final class IdentitasDetailController extends BaseController
{
    public function show(string $nomorInduk)
    {
        $detail = (new IdentitasDetailService())->show($nomorInduk, AuthContext::user());
        if ($detail === null) {
            return ApiResponse::fail('Identitas tidak ditemukan.', 404);
        }

        return ApiResponse::ok($detail);
    }

    public function form(string $nomorInduk)
    {
        $form = (new IdentitasFormService())->form($nomorInduk, AuthContext::user());
        if ($form === null) {
            return ApiResponse::fail('Identitas tidak ditemukan.', 404);
        }

        return ApiResponse::ok($form);
    }

    public function update(string $nomorInduk)
    {
        $input = $this->request->getJSON(true);
        if (! is_array($input)) {
            $input = $this->request->getPost() ?? [];
        }

        try {
            $result = (new IdentitasUpdateService())->update($nomorInduk, $input, AuthContext::user());
        } catch (IdentitasUpdateException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok($result);
    }

    public function destroy(string $nomorInduk)
    {
        $input = $this->request->getJSON(true);
        if (! is_array($input)) {
            $input = $this->request->getPost() ?? [];
        }
        $grant = (string) ($this->request->getHeaderLine('X-SDP-Supervisor-Grant')
            ?: ($input['grant'] ?? ''));

        try {
            $result = (new IdentitasDeleteService())->delete($nomorInduk, AuthContext::user(), $grant);
        } catch (IdentitasDeleteException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok($result);
    }
}
