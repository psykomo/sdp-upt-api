<?php

namespace App\Modules\Auth\Filters;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\JwtService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Tenancy\TenantContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if ($request->getMethod() === 'options') {
            return null;
        }

        $header = $request->getHeaderLine('Authorization');
        if (! preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            return ApiResponse::fail('Tidak terautentikasi.', 401);
        }

        try {
            $jwt    = new JwtService();
            $claims = $jwt->decode(trim($m[1]));
            $auth   = new AuthService(
                db_connect(),
                $jwt,
                new \App\Modules\Auth\Services\SiteContextService(db_connect()),
                new \App\Modules\Auth\Services\RbacService(db_connect()),
            );
            $user = $auth->userFromClaims($claims);
            if ($user->id === '') {
                return ApiResponse::fail('Token tidak valid.', 401);
            }

            AuthContext::set($user);

            $tenantId = $request->getHeaderLine('X-Tenant-Id') ?: $user->uptId;
            (new TenantContext())->apply($tenantId ?: null);
        } catch (\Throwable $e) {
            return ApiResponse::fail($e->getMessage() ?: 'Token tidak valid.', 401);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
