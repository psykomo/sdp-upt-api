<?php

namespace App\Modules\Identitas\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Services\IdentitasCaseViewService;
use App\Modules\Identitas\Services\IdentitasDetailService;
use App\Modules\Identitas\Services\IdentitasFormService;
use App\Modules\Identitas\Services\IdentitasUpdateException;
use App\Modules\Identitas\Services\IdentitasUpdateService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Database\UnitOfWork;
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
        $db      = db_connect();
        $service = new IdentitasUpdateService(
            new IdentitasRepository($db),
            new RbacService($db),
            new UnitOfWork($db),
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

    private function detailService(): IdentitasDetailService
    {
        $db = db_connect();

        return new IdentitasDetailService(
            new IdentitasRepository($db),
            new DeskripsiLookup($db),
            new IdentitasCaseViewService($db),
            new RbacService($db),
        );
    }

    private function formService(): IdentitasFormService
    {
        $db = db_connect();

        return new IdentitasFormService(
            new IdentitasRepository($db),
            new DeskripsiLookup($db),
            new IdentitasCaseViewService($db),
            new RbacService($db),
        );
    }
}
