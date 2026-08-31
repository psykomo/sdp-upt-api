<?php

namespace App\Modules\Shared\Support;

final class StoredUploadPath
{
    public static function legacyRoot(): string
    {
        $root = rtrim((string) env('legacy.root', ''), '/');
        if ($root === '') {
            $root = dirname(__DIR__, 5) . '/102sdp/sdp';
        }

        return $root;
    }

    public static function relative(mixed $path): ?string
    {
        $rel = str_replace('\\', '/', trim((string) $path));
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            return null;
        }

        $rel = ltrim($rel, '/');
        if (! str_starts_with($rel, 'upload/')) {
            return null;
        }

        return $rel;
    }

    public static function absolute(string $root, mixed $path): ?string
    {
        $rel = self::relative($path);
        if ($rel === null) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $full = $root . '/' . $rel;

        $realRoot = realpath($root);
        if ($realRoot === false) {
            return $full;
        }

        $realRoot = str_replace('\\', '/', $realRoot);
        $realFile = realpath($full);
        if ($realFile === false) {
            return $full;
        }

        $realFile = str_replace('\\', '/', $realFile);
        if ($realFile !== $realRoot && ! str_starts_with($realFile, $realRoot . '/')) {
            return null;
        }

        return $full;
    }
}
