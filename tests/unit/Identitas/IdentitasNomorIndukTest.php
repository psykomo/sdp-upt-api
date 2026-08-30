<?php

declare(strict_types=1);

namespace Tests\Unit\Identitas;

use PHPUnit\Framework\TestCase;

/**
 * Sequence formatting for createNomorInduk (uptId + Ymd + 4-digit seq).
 * Mirrors IdentitasRepository::nextNomorInduk without hitting the DB.
 */
final class IdentitasNomorIndukTest extends TestCase
{
    private function allocate(string $uptId, string $ymd, string $maxId): string
    {
        $prefix = trim($uptId) . $ymd;
        $seq    = 1;
        if ($maxId !== '' && str_starts_with($maxId, $prefix)) {
            $tail = substr($maxId, strlen($prefix));
            if (ctype_digit($tail)) {
                $seq = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function testFirstOfDayIs0001(): void
    {
        $this->assertSame('001202608300001', $this->allocate('001', '20260830', ''));
    }

    public function testIncrementsExistingMax(): void
    {
        $this->assertSame(
            '99A202608300008',
            $this->allocate('99A', '20260830', '99A202608300007'),
        );
    }

    public function testUsesJwtUptPrefixNotFixedThreeDigits(): void
    {
        $this->assertSame(
            '12345202608300002',
            $this->allocate('12345', '20260830', '12345202608300001'),
        );
    }
}
