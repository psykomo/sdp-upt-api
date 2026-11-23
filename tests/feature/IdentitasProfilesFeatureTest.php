<?php

use Tests\Support\ProfileApiTestCase;

/**
 * Multi-profile parity & row-scope verification across UPT, Pusat, Kanwil, and multi-tenant schemas.
 *
 * @internal
 */
final class IdentitasProfilesFeatureTest extends ProfileApiTestCase
{
    private const NOIN_UPT_093 = '093202607300006';
    private const NOIN_UPT_001 = '001202608290001';

    public function testUptSingleProfileSearchDetailAndForm(): void
    {
        $this->useProfile('single', 'db_sdp');
        $auth = $this->loginAs('admin', 'admin');

        $this->assertFalse($auth['user']['isPusat']);
        $this->assertFalse($auth['user']['isKanwil']);
        $this->assertSame('093', $auth['user']['uptId']);

        $me = json_decode($this->apiGet('/api/v1/auth/me', $auth['token'])->getJSON(), true);
        $this->assertTrue($me['data']['access']['10']['canWrite']);

        $search = $this->apiGet('/api/v1/identitas?perPage=5', $auth['token']);
        $search->assertStatus(200);
        $searchJson = json_decode($search->getJSON(), true);
        $this->assertNotEmpty($searchJson['data']['items']);

        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_093, $auth['token'])->assertStatus(200);
        $this->apiGet('/api/v1/identitas/form', $auth['token'])->assertStatus(200);
    }

    public function testPusatSharedDatabaseSeesAllUptsBlocksWritesAndEnforcesWorkingScope(): void
    {
        $this->useProfile('shared_database', 'sdp_pusat');
        $auth = $this->loginAs('admin', 'admin');

        $this->assertTrue($auth['user']['isPusat']);
        $this->assertFalse($auth['user']['isKanwil']);

        $search = $this->apiGet(
            '/api/v1/identitas?field=no_induk&q=' . self::NOIN_UPT_001 . '&activeOnly=0',
            $auth['token'],
        );
        $search->assertStatus(200);
        $searchJson = json_decode($search->getJSON(), true);
        $this->assertCount(1, $searchJson['data']['items']);
        $this->assertContains('asalUpt', $searchJson['data']['columns']);

        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_001, $auth['token'])->assertStatus(200);
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_093, $auth['token'])->assertStatus(200);

        // HQ cannot mutate master identity data (tipe=1).
        $this->apiGet('/api/v1/identitas/form', $auth['token'])->assertStatus(403);
        $this->apiPost('/api/v1/identitas', ['namaLengkap' => 'Blocked'], $auth['token'])->assertStatus(403);

        // Working-scope filter narrows Pusat view to UPT 093 and blocks cross-UPT access.
        $scopeRes = $this->apiPost('/api/v1/auth/working-scope', ['filterUptId' => '093'], $auth['token']);
        $scopeRes->assertStatus(200);
        $scopedToken = (string) (json_decode($scopeRes->getJSON(), true)['data']['token'] ?? '');

        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_093, $scopedToken)->assertStatus(200);
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_001, $scopedToken)->assertStatus(403);
    }

    public function testKanwilSharedDatabaseScopesToOwnKanwilAndRejectsOtherKanwil(): void
    {
        $this->useProfile('shared_database', 'sdp_pusat');
        $auth = $this->loginAs('kanwil_jambi', 'admin');

        $this->assertFalse($auth['user']['isPusat']);
        $this->assertTrue($auth['user']['isKanwil']);
        $this->assertSame('05', $auth['user']['uptId']);

        // UPT 001 is in Kanwil 01 -> hidden from Kanwil 05 search and 403 on detail.
        $search = $this->apiGet(
            '/api/v1/identitas?field=no_induk&q=' . self::NOIN_UPT_001 . '&activeOnly=0',
            $auth['token'],
        );
        $search->assertStatus(200);
        $searchJson = json_decode($search->getJSON(), true);
        $this->assertSame([], $searchJson['data']['items']);

        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_001, $auth['token'])->assertStatus(403);
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_093, $auth['token'])->assertStatus(200);

        // Kanwil cannot switch working-scope to a UPT outside its Kanwil.
        $this->apiPost('/api/v1/auth/working-scope', ['filterUptId' => '001'], $auth['token'])->assertStatus(403);
    }

    public function testUptOfficerOnSharedDatabaseScopesToOwnUptOnly(): void
    {
        $this->useProfile('shared_database', 'sdp_pusat');
        $auth = $this->loginAs('supervisor', 'supervisor', '093');

        $this->assertFalse($auth['user']['isPusat']);
        $this->assertFalse($auth['user']['isKanwil']);
        $this->assertSame('093', $auth['user']['uptId']);

        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_093, $auth['token'])->assertStatus(200);
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_001, $auth['token'])->assertStatus(403);
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_001 . '/form', $auth['token'])->assertStatus(403);
        $this->apiDelete('/api/v1/identitas/' . self::NOIN_UPT_001, $auth['token'])->assertStatus(403);
    }

    public function testDatabasePerTenantFailsClosedAndIsolatesSchemas(): void
    {
        $this->useProfile('database_per_tenant', 'db093');

        // Missing tenantId must fail closed (422).
        $this->apiPost('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin'])->assertStatus(422);

        $auth093 = $this->loginAs('admin', 'admin', '093');
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_093, $auth093['token'])->assertStatus(200);
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_001, $auth093['token'])->assertStatus(404);

        $auth001 = $this->loginAs('admin', 'admin', '001');
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_001, $auth001['token'])->assertStatus(200);
        $this->apiGet('/api/v1/identitas/' . self::NOIN_UPT_093, $auth001['token'])->assertStatus(404);
    }
}
