<?php

namespace App\Modules\Identitas\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\JwtService;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Auth\Services\SiteContextService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Services\IdentitasCaseViewService;
use App\Modules\Identitas\Services\IdentitasDetailService;
use App\Modules\Identitas\Services\IdentitasFormService;
use App\Modules\Identitas\Services\IdentitasDeleteException;
use App\Modules\Identitas\Services\IdentitasDeleteService;
use App\Modules\Identitas\Services\IdentitasUpdateException;
use App\Modules\Identitas\Services\IdentitasUpdateService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\DeskripsiLookup;

final class IdentitasDetailController extends BaseController
{
    public function show(string $nomorInduk)
    {
        $detail = $this->detailService()->show($nomorInduk, AuthContext::user());
        if ($detail === null) {
            return ApiResponse::fail('Identitas tidak ditemukan.', 404);
        }

        return ApiResponse::ok($detail);
    }

    public function form(string $nomorInduk)
    {
        $form = $this->formService()->form($nomorInduk, AuthContext::user());
        if ($form === null) {
            return ApiResponse::fail('Identitas tidak ditemukan.', 404);
        }

        return ApiResponse::ok($form);
    }

    public function update(string $nomorInduk)
    {
        $service = new IdentitasUpdateService(
            new IdentitasRepository(),
            new RbacService(),
        );

        $input = $this->request->getJSON(true);
        if (! is_array($input)) {
            $input = $this->request->getPost() ?? [];
        }

        try {
            $result = $service->update($nomorInduk, $input, AuthContext::user());
        } catch (IdentitasUpdateException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok($result);
    }

    public function destroy(string $nomorInduk)
    {
        $auth = new AuthService(
            new JwtService(),
            new SiteContextService(),
            new RbacService(),
        );
        $service = new IdentitasDeleteService(
            new IdentitasRepository(),
            new RbacService(),
            $auth,
        );

        $input = $this->request->getJSON(true);
        if (! is_array($input)) {
            $input = $this->request->getPost() ?? [];
        }
        $grant = (string) ($this->request->getHeaderLine('X-SDP-Supervisor-Grant')
            ?: ($input['grant'] ?? ''));

        try {
            $result = $service->delete($nomorInduk, AuthContext::user(), $grant);
        } catch (IdentitasDeleteException $e) {
            return ApiResponse::fail($e->getMessage(), $e->status, $e->errors);
        }

        return ApiResponse::ok($result);
    }

    private function detailService(): IdentitasDetailService
    {
        return new IdentitasDetailService(
            new IdentitasRepository(),
            new DeskripsiLookup(),
            new IdentitasCaseViewService(),
            new RbacService(),
        );
    }

    private function formService(): IdentitasFormService
    {
        return new IdentitasFormService(
            new IdentitasRepository(),
            new DeskripsiLookup(),
            new IdentitasCaseViewService(),
            new RbacService(),
        );
    }
}
