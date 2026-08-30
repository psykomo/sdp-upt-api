<?php

declare(strict_types=1);

namespace Tests\Unit\Identitas;

use App\Modules\Identitas\Services\IdentitasWriteMapper;
use PHPUnit\Framework\TestCase;

final class IdentitasWriteMapperTest extends TestCase
{
    public function testApplyBinForMale(): void
    {
        $mapper = new IdentitasWriteMapper();
        $this->assertSame(
            'BUDI BIN SUTEJO',
            $mapper->applyBinBinti('BUDI', 'SUTEJO', 'L'),
        );
    }

    public function testApplyBintiForFemale(): void
    {
        $mapper = new IdentitasWriteMapper();
        $this->assertSame(
            'SITI BINTI SUTEJO',
            $mapper->applyBinBinti('SITI', 'SUTEJO', 'P'),
        );
    }

    public function testApplyBinSkipsWhenAyahEmpty(): void
    {
        $mapper = new IdentitasWriteMapper();
        $this->assertSame('BUDI', $mapper->applyBinBinti('BUDI', '', 'L'));
    }
}
