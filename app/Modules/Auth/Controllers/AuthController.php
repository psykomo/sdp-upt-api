<?php

namespace App\Modules\Auth\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\JwtService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;
use CodeIgniter\HTTP\ResponseInterface;

final class AuthController extends BaseController
{
    private function service(): AuthService
    {
        return new AuthService();
    }

    public function login()
    {
        $json = $this->request->getJSON(true) ?? [];
        $username = (string) ($json['username'] ?? $this->request->getPost('username') ?? '');
        $password = (string) ($json['password'] ?? $this->request->getPost('password') ?? '');
        $tenantId = (string) ($json['tenantId'] ?? $json['uptId'] ?? $this->request->getPost('tenantId')
            ?? $this->request->getHeaderLine('X-Tenant-Id') ?? '');

        try {
            $result = $this->service()->login($username, $password, $tenantId !== '' ? $tenantId : null);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $status = in_array($code, [401, 422, 429], true) ? $code : 400;

            return ApiResponse::fail($e->getMessage(), $status);
        }

        return $this->withTokenCookie(ApiResponse::ok($result), $result['token']);
    }

    public function cookie()
    {
        $json  = $this->request->getJSON(true) ?? [];
        $token = trim((string) ($json['token'] ?? $this->request->getPost('token') ?? ''));
        if ($token === '') {
            return ApiResponse::fail('Token tidak valid.', 401);
        }

        try {
            $claims = (new JwtService())->decode($token);
            $user   = $this->service()->userFromClaims($claims);
            if ($user->id === '') {
                return ApiResponse::fail('Token tidak valid.', 401);
            }
        } catch (\Throwable $e) {
            return ApiResponse::fail('Token tidak valid.', 401);
        }

        return $this->withTokenCookie(ApiResponse::ok(['ok' => true]), $token);
    }

    public function logout()
    {
        return $this->expireTokenCookie(ApiResponse::ok(['ok' => true]));
    }

    public function me()
    {
        return ApiResponse::ok($this->service()->me(AuthContext::user()));
    }

    public function supervisor()
    {
        $json = $this->request->getJSON(true) ?? [];
        $username   = (string) ($json['username'] ?? $this->request->getPost('username') ?? '');
        $password   = (string) ($json['password'] ?? $this->request->getPost('password') ?? '');
        $nomorInduk = (string) ($json['nomorInduk'] ?? $this->request->getPost('nomorInduk') ?? '');

        try {
            $result = $this->service()->issueHapusGrant(
                AuthContext::user(),
                $username,
                $password,
                $nomorInduk,
            );
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $status = in_array($code, [401, 403, 422, 429], true) ? $code : 400;

            return ApiResponse::fail($e->getMessage(), $status);
        }

        return ApiResponse::ok($result);
    }

    private function withTokenCookie(ResponseInterface $response, string $token): ResponseInterface
    {
        $ttl = max(1, (int) env('jwt.ttlHours', 8) * 3600);

        return $response->setCookie([
            'name'     => 'sdp_token',
            'value'    => $token,
            'expire'   => $ttl,
            'path'     => '/',
            'prefix'   => '',
            'domain'   => '',
            'secure'   => $this->request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function expireTokenCookie(ResponseInterface $response): ResponseInterface
    {
        return $response->deleteCookie('sdp_token', '', '/');
    }
}
