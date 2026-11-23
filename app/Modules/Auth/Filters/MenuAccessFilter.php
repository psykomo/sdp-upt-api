<?php

namespace App\Modules\Auth\Filters;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class MenuAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $menuId = $arguments[0] ?? null;
        if ($menuId === null) {
            return null;
        }

        $user = AuthContext::tryUser();
        if ($user === null) {
            return ApiResponse::fail('Tidak terautentikasi.', 401);
        }

        $tipe   = isset($arguments[1]) ? (int) $arguments[1] : 1;
        $rbac   = new RbacService();
        $access = $rbac->accessFor($user, (string) $menuId, $tipe);
        if ($access['level'] === 'N') {
            return ApiResponse::fail('Mohon maaf anda tidak berhak mengakses halaman ini.', 403);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
