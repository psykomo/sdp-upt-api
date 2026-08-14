<?php

namespace App\Modules\Shared\Support;

final class DateFormat
{
    public static function toDisplay(?string $ymd, string $separator = '/'): ?string
    {
        if ($ymd === null || trim($ymd) === '' || $ymd === '0000-00-00') {
            return null;
        }

        $parts = explode('-', substr($ymd, 0, 10));
        if (count($parts) !== 3) {
            return $ymd;
        }

        return $parts[2] . $separator . $parts[1] . $separator . $parts[0];
    }

    public static function fromDisplay(?string $dmy): ?string
    {
        if ($dmy === null || trim($dmy) === '') {
            return null;
        }

        $dmy = str_replace('-', '/', trim($dmy));
        $parts = explode('/', $dmy);
        if (count($parts) !== 3) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0]);
    }
}
