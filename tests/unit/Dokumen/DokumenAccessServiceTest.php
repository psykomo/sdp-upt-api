<?php

declare(strict_types=1);

namespace Tests\Unit\Dokumen;

use App\Modules\Dokumen\Services\DokumenAccessService;
use App\Modules\Dokumen\Services\DokumenException;
use App\Modules\Identitas\Repositories\IdentitasRowScopeRepository;
use App\Modules\Identitas\Services\IdentitasAccessService;
use App\Modules\Shared\Auth\CurrentUser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DokumenAccessServiceTest extends CIUnitTestCase
{
    public function testUnknownJenisIsDenied(): void
    {
        $user = new CurrentUser('u1', 'role', 'Name', '1', true, false, null, null, []);

        $this->expectException(DokumenException::class);
        $this->expectExceptionMessage('Mohon maaf anda tidak berhak mengakses dokumen ini.');
        (new DokumenAccessService())->assertRowAccessible([
            'JENIS_DOKUMEN' => 'perkara',
            'ID_REFERENCE'  => 'PK1',
        ], $user);
    }

    public function testResolveStorageUptIdUsesSessionUpt(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->expects($this->never())->method('activeStaParentPerkaraContext');

        $user = new CurrentUser('u1', 'role', 'Name', '1', false, false, 'UPT1', 'UPT', []);

        $this->assertSame(
            'UPT1',
            (new DokumenAccessService(new IdentitasAccessService($repo)))->resolveStorageUptId('NO001', $user),
        );
    }

    public function testResolveStorageUptIdFallsBackToParentPerkara(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->expects($this->once())
            ->method('activeStaParentPerkaraContext')
            ->with('NO001')
            ->willReturn([
                'idUpt'  => 'UPT9',
                'kanwil' => 'KW1',
            ]);

        $user = new CurrentUser('u1', 'role', 'Name', '1', true, false, null, null, []);

        $this->assertSame(
            'UPT9',
            (new DokumenAccessService(new IdentitasAccessService($repo)))->resolveStorageUptId('NO001', $user),
        );
    }

    public function testResolveStorageUptIdDefaultsTo000(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')->willReturn(null);

        $user = new CurrentUser('u1', 'role', 'Name', '1', true, false, null, null, []);

        $this->assertSame(
            '000',
            (new DokumenAccessService(new IdentitasAccessService($repo)))->resolveStorageUptId('NO001', $user),
        );
    }

    public function testIdentitasAccessDeniedMapsToDokumenException(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')->willReturn([
            'idUpt'  => 'UPT2',
            'kanwil' => 'KW1',
        ]);

        $user = new CurrentUser('u1', 'role', 'Name', '1', false, false, 'UPT1', 'UPT', []);

        $this->expectException(DokumenException::class);
        $this->expectExceptionMessage('Mohon maaf anda tidak berhak mengakses data identitas ini.');
        (new DokumenAccessService(new IdentitasAccessService($repo)))->assertIdentitasNomorInduk('NO001', $user);
    }
}
