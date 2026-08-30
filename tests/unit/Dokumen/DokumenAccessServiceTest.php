<?php

declare(strict_types=1);

namespace Tests\Unit\Dokumen;

use App\Modules\Dokumen\Services\DokumenAccessService;
use App\Modules\Identitas\Services\IdentitasAccessException;
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

        $this->expectException(IdentitasAccessException::class);
        (new DokumenAccessService())->assertRowAccessible([
            'JENIS_DOKUMEN' => 'perkara',
            'ID_REFERENCE'  => 'PK1',
        ], $user);
    }
}
