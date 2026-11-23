<?php

use App\Modules\Auth\Services\JwtService;
use Tests\Support\ProfileApiTestCase;

/**
 * @internal
 */
final class AuthHandoverFeatureTest extends ProfileApiTestCase
{
    public function testHandoverTokenCannotAuthenticateApiRoutesDirectly(): void
    {
        $this->useProfile('single', 'db_sdp');

        $handover = (new JwtService())->encodeWithTtl([
            'sub'      => 'admin',
            'typ'      => JwtService::TYP_HANDOVER,
            'role'     => 'admin',
            'name'     => 'Administrator',
            'level'    => '4',
            'isPusat'  => false,
            'isKanwil' => false,
            'uptId'    => '093',
            'uptName'  => 'Rutan Test',
        ], 60);

        $this->apiGet('/api/v1/auth/me', $handover)->assertStatus(401);
        $this->apiGet('/api/v1/identitas', $handover)->assertStatus(401);
    }

    public function testHandoverTokenExchangesForSessionCookieViaAuthCookie(): void
    {
        $this->useProfile('single', 'db_sdp');

        $handover = (new JwtService())->encodeWithTtl([
            'sub'      => 'admin',
            'typ'      => JwtService::TYP_HANDOVER,
            'role'     => 'admin',
            'name'     => 'Administrator',
            'level'    => '4',
            'isPusat'  => false,
            'isKanwil' => false,
            'uptId'    => '093',
            'uptName'  => 'Rutan Test',
        ], 60);

        $res = $this->apiPost('/api/v1/auth/cookie', ['token' => $handover]);
        $res->assertStatus(200);

        $cookie = $res->response()->getCookie('sdp_token');
        $this->assertNotNull($cookie);

        $sessionToken = $cookie->getValue();
        $this->assertNotSame($handover, $sessionToken);

        $claims = (new JwtService())->decode($sessionToken);
        $this->assertSame(JwtService::TYP_SESSION, $claims['typ'] ?? null);

        $meRes = $this->apiGet('/api/v1/auth/me', null, ['Cookie' => 'sdp_token=' . $sessionToken]);
        $meRes->assertStatus(200);

        $me = json_decode($meRes->getJSON(), true);
        $this->assertSame('admin', $me['data']['user']['id'] ?? null);
        $this->assertSame('10', $me['data']['menus'][0]['id'] ?? null);
        $this->assertArrayHasKey('10', $me['data']['access'] ?? []);
    }

    public function testSupervisorGrantsCannotBeReplayedAsSessionOrHandoverTokens(): void
    {
        $this->useProfile('single', 'db_sdp');

        foreach ([JwtService::TYP_SUPERVISOR, JwtService::TYP_HAPUS_LEGACY] as $typ) {
            $grant = (new JwtService())->encodeWithTtl([
                'sub'         => 'supervisor',
                'typ'         => $typ,
                'officer'     => 'admin',
                'menuId'      => '10',
                'action'      => 'delete',
                'resourceKey' => '093202607300006',
                'nomorInduk'  => '093202607300006',
                'name'        => 'Supervisor',
            ], 600);

            $this->apiGet('/api/v1/auth/me', $grant)->assertStatus(401);
            $this->apiPost('/api/v1/auth/cookie', ['token' => $grant])->assertStatus(401);
        }

        // Also verify real supervisor grant issuance cannot be used to authenticate API routes.
        $officer = $this->loginAs('admin', 'admin');
        $supRes  = $this->apiPost('/api/v1/auth/supervisor', [
            'username'    => 'supervisor',
            'password'    => 'supervisor',
            'menuId'      => '10',
            'resourceKey' => '093202607300006',
            'action'      => 'delete',
        ], $officer['token']);
        $supRes->assertStatus(200);
        $issuedGrant = (string) (json_decode($supRes->getJSON(), true)['data']['grant'] ?? '');
        $this->assertNotSame('', $issuedGrant);
        $this->apiGet('/api/v1/auth/me', $issuedGrant)->assertStatus(401);
    }

    public function testLogoutExpiresSdpTokenCookie(): void
    {
        $this->useProfile('single', 'db_sdp');

        $res = $this->apiPost('/api/v1/auth/logout');
        $res->assertStatus(200);

        $cookie = $res->response()->getCookie('sdp_token');
        $this->assertNotNull($cookie);
        $this->assertSame('', $cookie->getValue());
    }
}
