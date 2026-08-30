<?php

declare(strict_types=1);

namespace Tests\Unit\Identitas;

use App\Modules\Identitas\Repositories\IdentitasRepositoryInterface;
use App\Modules\Identitas\Services\IdentitasSensitiveFieldService;
use App\Modules\Shared\Auth\CurrentUser;
use PHPUnit\Framework\TestCase;

final class IdentitasSensitiveFieldServiceTest extends TestCase
{
    public function testNoStaParentLeavesSensitiveFieldsOpen(): void
    {
        $repo = $this->createMock(IdentitasRepositoryInterface::class);
        $repo->method('activeStaParentPerkaraId')->willReturn(null);

        $service = new IdentitasSensitiveFieldService($repo);
        $user    = new CurrentUser('u1', 'role', 'Name', '1', false, false, 'UPT1', 'UPT', []);

        $this->assertTrue($service->canEditSensitiveFields('NO001', $user));
        $this->assertSame([], $service->lockedFields('NO001', $user));
    }

    public function testProcessedRemisiWithoutSidangWindowLocksFields(): void
    {
        $repo = $this->createMock(IdentitasRepositoryInterface::class);
        $repo->method('activeStaParentPerkaraId')->willReturn('PK1');
        $repo->method('hasProcessedRemisi')->with('PK1')->willReturn(true);
        $repo->method('hasSidangTppAkses002')->willReturn(false);
        $repo->method('activeStaParentUptId')->willReturn('UPT1');

        $service = new IdentitasSensitiveFieldService($repo);
        $user    = new CurrentUser('u1', 'role', 'Name', '1', true, false, null, null, []);

        $this->assertFalse($service->canEditSensitiveFields('NO001', $user));
        $this->assertSame(IdentitasSensitiveFieldService::LOCKED_FIELDS, $service->lockedFields('NO001', $user));
    }

    public function testValidateChangesRejectsLockedTanggalLahir(): void
    {
        $repo = $this->createMock(IdentitasRepositoryInterface::class);
        $repo->method('activeStaParentPerkaraId')->willReturn('PK1');
        $repo->method('hasProcessedRemisi')->willReturn(true);
        $repo->method('hasSidangTppAkses002')->willReturn(false);
        $repo->method('activeStaParentUptId')->willReturn('UPT1');

        $service = new IdentitasSensitiveFieldService($repo);
        $user    = new CurrentUser('u1', 'role', 'Name', '1', false, false, 'UPT1', 'UPT', []);
        $errors  = $service->validateChanges(
            ['tanggalLahir' => '01/01/2000'],
            ['TANGGAL_LAHIR' => '2000-01-02'],
            'NO001',
            $user,
        );

        $this->assertArrayHasKey('tanggalLahir', $errors);
    }
}
