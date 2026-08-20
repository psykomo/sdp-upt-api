<?php

namespace App\Modules\Shared\Support;

/**
 * CI4 only prunes debugbar dumps when the HTML toolbar is opened.
 * JSON API responses never do that, so dumps accumulate unless we prune here.
 *
 * $limit matches Config\Toolbar::$maxHistory: 0 = none, -1 = unlimited.
 */
final class DebugbarHistory
{
    public static function prune(string $directory, int $limit): void
    {
        if ($limit < 0) {
            return;
        }

        $filenames = glob($directory . '/debugbar_*.json');
        if ($filenames === false || $filenames === []) {
            return;
        }

        sort($filenames, SORT_STRING);

        $counter = 0;
        foreach (array_reverse($filenames) as $filename) {
            $counter++;
            if ($counter > $limit && is_file($filename)) {
                unlink($filename);
            }
        }
    }
}
