<?php

namespace App\Modules\Auth\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Auth\Services\SiteContextService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\PublicOrigins;
use App\Modules\Shared\Tenancy\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Sdp;

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

    public function loginOptions()
    {
        $mode = (new TenantContext())->mode();
        $site = (new SiteContextService())->resolve();

        $requiresTenantId = $mode === TenantContext::MODE_DATABASE_PER_TENANT;
        $requiresWorkingUptId = $mode === TenantContext::MODE_SHARED_DATABASE && $site['isPusat'];

        return ApiResponse::ok([
            'tenancyMode'          => $mode,
            'requiresTenantId'     => $requiresTenantId,
            'requiresWorkingUptId' => $requiresWorkingUptId,
            'hasLegacyHost'        => PublicOrigins::hasLegacyBase(),
            'legacyBase'           => PublicOrigins::legacyBase(),
        ]);
    }

    public function cookie()
    {
        $json  = $this->request->getJSON(true) ?? [];
        $token = trim((string) ($json['token'] ?? $this->request->getPost('token') ?? ''));
        if ($token === '') {
            return ApiResponse::fail('Token tidak valid.', 401);
        }

        try {
            $sessionToken = $this->service()->exchangeHandoverToken($token);
        } catch (\Throwable $e) {
            return ApiResponse::fail('Token tidak valid.', 401);
        }

        return $this->withTokenCookie(ApiResponse::ok(['ok' => true]), $sessionToken);
    }

    public function logout()
    {
        return $this->expireTokenCookie(ApiResponse::ok(['ok' => true]));
    }

    public function me()
    {
        return ApiResponse::ok($this->service()->me(AuthContext::user()));
    }

    public function workingScope()
    {
        $json = $this->request->getJSON(true) ?? [];
        $filterUptId    = $json['filterUptId'] ?? $this->request->getPost('filterUptId') ?? null;
        $filterKanwilId = $json['filterKanwilId'] ?? $this->request->getPost('filterKanwilId') ?? null;

        try {
            $result = $this->service()->setWorkingScope(
                AuthContext::user(),
                $filterUptId !== null ? (string) $filterUptId : null,
                $filterKanwilId !== null ? (string) $filterKanwilId : null,
            );
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $status = in_array($code, [403, 422], true) ? $code : 400;

            return ApiResponse::fail($e->getMessage(), $status);
        }

        return $this->withTokenCookie(ApiResponse::ok($result), $result['token']);
    }

    public function supervisor()
    {
        $json = $this->request->getJSON(true) ?? [];
        $username    = (string) ($json['username'] ?? $this->request->getPost('username') ?? '');
        $password    = (string) ($json['password'] ?? $this->request->getPost('password') ?? '');
        $menuId      = (string) ($json['menuId'] ?? $this->request->getPost('menuId') ?? RbacService::MENU_IDENTITAS);
        $resourceKey = (string) ($json['resourceKey'] ?? $json['nomorInduk'] ?? $this->request->getPost('resourceKey') ?? $this->request->getPost('nomorInduk') ?? '');
        $action      = (string) ($json['action'] ?? $this->request->getPost('action') ?? 'delete');

        try {
            $result = $this->service()->issueSupervisorGrant(
                AuthContext::user(),
                $username,
                $password,
                $menuId,
                $resourceKey,
                $action,
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
        $ttl = max(1, Sdp::current()->jwtTtlHours * 3600);

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
