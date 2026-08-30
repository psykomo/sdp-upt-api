<?php

declare(strict_types=1);

namespace Tests\Unit\Identitas;

use App\Modules\Identitas\Services\IdentitasCreateException;
use App\Modules\Identitas\Services\IdentitasCreateService;
use App\Modules\Shared\Auth\CurrentUser;
use PHPUnit\Framework\TestCase;

final class IdentitasCreateServiceTest extends TestCase
{
    public function testHqCannotCreate(): void
    {
        $service = new IdentitasCreateService();
        $user    = new CurrentUser('u1', 'role', 'HQ', '1', true, false, null, null, []);

        $this->expectException(IdentitasCreateException::class);
        try {
            $service->create(['namaLengkap' => 'A'], $user);
        } catch (IdentitasCreateException $e) {
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function testKanwilCannotCreate(): void
    {
        $service = new IdentitasCreateService();
        $user    = new CurrentUser('u1', 'role', 'Kanwil', '1', false, true, 'KW1', 'Kanwil', []);

        $this->expectException(IdentitasCreateException::class);
        try {
            $service->create(['namaLengkap' => 'A'], $user);
        } catch (IdentitasCreateException $e) {
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function testMissingUptIdForbidden(): void
    {
        $service = new IdentitasCreateService();
        $user    = new CurrentUser('u1', 'role', 'Officer', '5', false, false, null, null, []);

        $this->expectException(IdentitasCreateException::class);
        try {
            $service->create(['namaLengkap' => 'A'], $user);
        } catch (IdentitasCreateException $e) {
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function testDuplicateExceptionCarriesMatches(): void
    {
        $matches = [
            [
                'nomorInduk'   => 'UPT001',
                'namaLengkap'  => 'BUDI BIN AYAH',
                'href'         => 'http://127.0.0.1:8082/identitas/UPT001',
            ],
        ];
        $e = new IdentitasCreateException('dup', [], 409, $matches);
        $this->assertSame(409, $e->status);
        $this->assertSame('UPT001', $e->matches[0]['nomorInduk']);
    }
}
