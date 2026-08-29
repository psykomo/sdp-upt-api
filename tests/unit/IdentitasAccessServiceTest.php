<?php

use App\Modules\Identitas\DTO\IdentitasSearchQuery;
use App\Modules\Identitas\Repositories\IdentitasRowScopeRepository;
use App\Modules\Identitas\Services\IdentitasAccessException;
use App\Modules\Identitas\Services\IdentitasAccessService;
use App\Modules\Shared\Auth\CurrentUser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class IdentitasAccessServiceTest extends CIUnitTestCase
{
    private function uptOfficer(string $uptId = '093'): CurrentUser
    {
        return new CurrentUser(
            id: 'u1',
            role: 'admin',
            name: 'UPT',
            level: '1',
            isPusat: false,
            isKanwil: false,
            uptId: $uptId,
            uptName: 'UPT Test',
        );
    }

    private function kanwilOfficer(string $kanwilCode = '05'): CurrentUser
    {
        return new CurrentUser(
            id: 'k1',
            role: 'admin',
            name: 'Kanwil',
            level: '4',
            isPusat: false,
            isKanwil: true,
            uptId: $kanwilCode,
            uptName: 'Kanwil Test',
        );
    }

    private function pusatOfficer(): CurrentUser
    {
        return new CurrentUser(
            id: 'p1',
            role: 'admin',
            name: 'Pusat',
            level: '1',
            isPusat: true,
            isKanwil: false,
            uptId: null,
            uptName: 'Ditjen',
        );
    }

    public function testUptOfficerAllowsMatchingUpt(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')
            ->with('N001')
            ->willReturn(['idUpt' => '093', 'kanwil' => '05']);

        $service = new IdentitasAccessService($repo);
        $service->assertNomorIndukAccessible('N001', $this->uptOfficer('093'));
        $this->addToAssertionCount(1);
    }

    public function testUptOfficerDeniesOtherUpt(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')
            ->with('N001')
            ->willReturn(['idUpt' => '001', 'kanwil' => '12']);

        $service = new IdentitasAccessService($repo);

        $this->expectException(IdentitasAccessException::class);
        $service->assertNomorIndukAccessible('N001', $this->uptOfficer('093'));
    }

    public function testKanwilOfficerAllowsMatchingKanwil(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')
            ->with('N001')
            ->willReturn(['idUpt' => '093', 'kanwil' => '05']);

        $service = new IdentitasAccessService($repo);
        $service->assertNomorIndukAccessible('N001', $this->kanwilOfficer('05'));
        $this->addToAssertionCount(1);
    }

    public function testKanwilOfficerDeniesOtherKanwil(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')
            ->with('N001')
            ->willReturn(['idUpt' => '001', 'kanwil' => '12']);

        $service = new IdentitasAccessService($repo);

        $this->expectException(IdentitasAccessException::class);
        $service->assertNomorIndukAccessible('N001', $this->kanwilOfficer('05'));
    }

    public function testNoActiveStaParentAllowsUptAndKanwil(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')
            ->with('N001')
            ->willReturn(null);

        $service = new IdentitasAccessService($repo);
        $service->assertNomorIndukAccessible('N001', $this->uptOfficer('093'));
        $service->assertNomorIndukAccessible('N001', $this->kanwilOfficer('05'));
        $this->addToAssertionCount(1);
    }

    public function testPusatNeverThrows(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')
            ->with('N001')
            ->willReturn(['idUpt' => '001', 'kanwil' => '12']);

        $service = new IdentitasAccessService($repo);
        $service->assertNomorIndukAccessible('N001', $this->pusatOfficer());
        $this->addToAssertionCount(1);
    }

    public function testApplySearchScopeNoOpForPusat(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $service = new IdentitasAccessService($repo);
        $builder = db_connect()->table('identitas');
        $query   = new IdentitasSearchQuery(
            field: 'semua',
            q: '',
            photoStatus: 'semua',
            fingerprintStatus: 'semua',
            activeOnly: true,
            recidivistOnly: false,
            mode: 'grid',
            page: 1,
            perPage: 20,
            sort: 'noin',
            order: 'asc',
            exportAll: false,
            includeCase: false,
        );

        $service->applySearchScope($builder, $this->pusatOfficer(), $query);

        $sql = $builder->getCompiledSelect();
        $this->assertStringNotContainsString('p_scope', $sql);
        $this->assertStringNotContainsString('upt.KANWIL', $sql);
    }
}
