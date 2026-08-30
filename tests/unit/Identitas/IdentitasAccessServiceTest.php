<?php

declare(strict_types=1);

namespace Tests\Unit\Identitas;

use App\Modules\Identitas\Repositories\IdentitasRowScopeRepository;
use App\Modules\Identitas\Services\IdentitasAccessException;
use App\Modules\Identitas\Services\IdentitasAccessService;
use App\Modules\Shared\Auth\CurrentUser;
use PHPUnit\Framework\TestCase;

final class IdentitasAccessServiceTest extends TestCase
{
    public function testPusatUserCanAccessWithoutProfileScope(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->expects($this->never())->method('activeStaParentPerkaraContext');

        $service = new IdentitasAccessService($repo);
        $user    = new CurrentUser('u1', 'role', 'Name', '1', true, false, null, null, []);

        $service->assertNomorIndukAccessible('NO001', $user);
        $this->assertTrue(true);
    }

    public function testUptUserDeniedOutsideOwnUpt(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')->willReturn([
            'idUpt'  => 'UPT2',
            'kanwil' => 'KW1',
        ]);

        $service = new IdentitasAccessService($repo);
        $user    = new CurrentUser('u1', 'role', 'Name', '1', false, false, 'UPT1', 'UPT', []);

        $this->expectException(IdentitasAccessException::class);
        $service->assertNomorIndukAccessible('NO001', $user);
    }

    public function testKanwilUserAllowedInsideWilayah(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')->willReturn([
            'idUpt'  => 'UPT9',
            'kanwil' => 'KW1',
        ]);

        $service = new IdentitasAccessService($repo);
        $user    = new CurrentUser('u1', 'role', 'Name', '1', false, true, 'KW1', 'Kanwil', []);

        $service->assertNomorIndukAccessible('NO001', $user);
        $this->assertTrue(true);
    }

    public function testSessionFilterUptBlocksOtherUpt(): void
    {
        $repo = $this->createMock(IdentitasRowScopeRepository::class);
        $repo->method('activeStaParentPerkaraContext')->willReturn([
            'idUpt'  => 'UPT2',
            'kanwil' => 'KW1',
        ]);

        $service = new IdentitasAccessService($repo);
        $user    = new CurrentUser(
            'u1',
            'role',
            'Name',
            '1',
            true,
            false,
            null,
            null,
            ['sessionFilterUptId' => 'UPT1'],
        );

        $this->expectException(IdentitasAccessException::class);
        $service->assertNomorIndukAccessible('NO001', $user);
    }
}
