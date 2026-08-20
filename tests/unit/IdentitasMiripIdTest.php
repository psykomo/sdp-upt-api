<?php

use App\Modules\Identitas\Repositories\IdentitasRepository;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class IdentitasMiripIdTest extends CIUnitTestCase
{
    public function testEmptyUptUsesCompactHex(): void
    {
        $id = IdentitasRepository::formatIdentitasMiripId(null, null);

        $this->assertSame(32, strlen($id));
        $this->assertTrue(ctype_xdigit($id));
    }

    public function testFirstIdIsUptDateAndSequence(): void
    {
        $id = IdentitasRepository::formatIdentitasMiripId('0501', null);

        $this->assertSame('0501' . date('Ymd') . '0001', $id);
    }

    public function testIncrementsNumericSuffix(): void
    {
        $prefix = '0501' . date('Ymd');
        $id     = IdentitasRepository::formatIdentitasMiripId('0501', $prefix . '0001');

        $this->assertSame($prefix . '0002', $id);
    }

    public function testIgnoresMaxIdFromAnotherPrefix(): void
    {
        $id = IdentitasRepository::formatIdentitasMiripId('0501', '0201' . date('Ymd') . '0099');

        $this->assertSame('0501' . date('Ymd') . '0001', $id);
    }
}
