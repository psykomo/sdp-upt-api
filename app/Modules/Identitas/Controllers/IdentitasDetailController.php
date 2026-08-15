<?php

namespace App\Modules\Identitas\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\Services\IdentitasCaseViewService;
use App\Modules\Identitas\Services\IdentitasDetailService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\DeskripsiLookup;

final class IdentitasDetailController extends BaseController
{
    public function show(string $nomorInduk)
    {
        $db      = db_connect();
        $service = new IdentitasDetailService(
            $db,
            new DeskripsiLookup($db),
            new IdentitasCaseViewService($db),
            new RbacService($db),
        );

        $detail = $service->show($nomorInduk, AuthContext::user());
        if ($detail === null) {
            return ApiResponse::fail('Identitas tidak ditemukan.', 404);
        }

        return ApiResponse::ok($detail);
    }
}
