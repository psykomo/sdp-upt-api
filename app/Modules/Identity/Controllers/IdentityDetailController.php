<?php

namespace App\Modules\Identity\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Identity\Services\IdentityCaseViewService;
use App\Modules\Identity\Services\IdentityDetailService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\DeskripsiLookup;

final class IdentityDetailController extends BaseController
{
    public function show(string $nomorInduk)
    {
        $db      = db_connect();
        $service = new IdentityDetailService(
            $db,
            new DeskripsiLookup($db),
            new IdentityCaseViewService($db),
            new RbacService($db),
        );

        $detail = $service->show($nomorInduk, AuthContext::user());
        if ($detail === null) {
            return ApiResponse::fail('Identitas tidak ditemukan.', 404);
        }

        return ApiResponse::ok($detail);
    }
}
