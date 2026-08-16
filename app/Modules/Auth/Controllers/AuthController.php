<?php

namespace App\Modules\Auth\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\JwtService;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Auth\Services\SiteContextService;
use App\Modules\Shared\Auth\AuthContext;
use App\Modules\Shared\Http\ApiResponse;

final class AuthController extends BaseController
{
    private function service(): AuthService
    {
        $db = db_connect();

        return new AuthService(
            $db,
            new JwtService(),
            new SiteContextService($db),
            new RbacService($db),
        );
    }

    public function login()
    {
        $json = $this->request->getJSON(true) ?? [];
        $username = (string) ($json['username'] ?? $this->request->getPost('username') ?? '');
        $password = (string) ($json['password'] ?? $this->request->getPost('password') ?? '');

        try {
            $result = $this->service()->login($username, $password);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $status = in_array($code, [401, 422, 429], true) ? $code : 400;

            return ApiResponse::fail($e->getMessage(), $status);
        }

        return ApiResponse::ok($result);
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
}
