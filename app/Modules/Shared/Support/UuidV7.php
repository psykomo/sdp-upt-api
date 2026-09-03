<?php

namespace App\Modules\Shared\Support;

/**
 * Basic UUID v7 Generator (Time-ordered, no external dependencies)
 */
final class UuidV7
{
    /**
     * Generate a UUID v7 string
     *
     * @return string e.g. "018a6b2c-63b7-7e61-8b4b-4a3b7c8d9e0f"
     */
    public static function generate(?string $id_upt = null): string
    {
        // Get current time in milliseconds
        $ms = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);

        if (!empty($id_upt)) {
            // Menggabungkan hash dari ID_UPT untuk memastikan tidak ada duplikasi lintas UPT
            $uptHash = substr(md5($id_upt), 0, 10);
            $trueRand = bin2hex(random_bytes(5)); // 10 hex chars
            $rand = $uptHash . $trueRand; // Total 20 hex chars
        } else {
            $rand = bin2hex(random_bytes(10)); // 20 hex chars
        }

        // Format as UUID v7
        // version 7 (0111) in the high nibble of time_hi_and_version
        // variant (10xx) in the high nibble of clock_seq_hi_and_reserved
        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($rand, 0, 3),
            dechex(8 | (hexdec($rand[3]) & 0x3)) . substr($rand, 4, 3),
            substr($rand, 7, 12)
        );
    }
}
