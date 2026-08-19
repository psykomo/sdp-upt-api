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

        $token = $this->tokenFrom($request);
        if ($token === null) {
            return ApiResponse::fail('Tidak terautentikasi.', 401);
        }

        try {
            $jwt    = new JwtService();
            $claims = $jwt->decode($token);
            $auth   = new AuthService(
                $jwt,
                new \App\Modules\Auth\Services\SiteContextService(),
                new \App\Modules\Auth\Services\RbacService(),
            );
            $user = $auth->userFromClaims($claims);
            if ($user->id === '') {
                return ApiResponse::fail('Token tidak valid.', 401);
            }

            AuthContext::set($user);
            (new TenantContext())->apply($user->uptId);
        } catch (\Throwable $e) {
            return ApiResponse::fail($e->getMessage() ?: 'Token tidak valid.', 401);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }

    private function tokenFrom(RequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            $bearer = trim($m[1]);
            if ($bearer !== '') {
                return $bearer;
            }
        }

        if ($request instanceof \CodeIgniter\HTTP\IncomingRequest) {
            $cookie = $request->getCookie('sdp_token');
            if (is_string($cookie) && $cookie !== '') {
                return $cookie;
            }
        }

        $raw = $request->getHeaderLine('Cookie');
        if ($raw !== '' && preg_match('/(?:^|;\s*)sdp_token=([^;]+)/', $raw, $m)) {
            $cookie = rawurldecode($m[1]);
            if ($cookie !== '') {
                return $cookie;
            }
        }

        return null;
    }
}
