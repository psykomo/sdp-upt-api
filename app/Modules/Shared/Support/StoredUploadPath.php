<?php

namespace App\Modules\Shared\Support;

use Config\Sdp;

final class StoredUploadPath
{
    public static function legacyRoot(): string
    {
        return self::resolveLegacyRoot(Sdp::current()->legacyRoot);
    }

    public static function resolveLegacyRoot(string $configured, ?string $apiRoot = null): string
    {
        $base = rtrim(str_replace('\\', '/', $apiRoot ?? (defined('ROOTPATH') ? ROOTPATH : dirname(__DIR__, 4))), '/');
        $raw  = rtrim(str_replace('\\', '/', trim($configured)), '/');

        if ($raw !== '') {
            if (
                str_starts_with($raw, '/')
                || preg_match('/^[A-Za-z]:(?:\/|$)/', $raw) === 1
                || str_contains($raw, '://')
            ) {
                return $raw;
            }

            return self::normalizeRelativeJoin($base, $raw);
        }

        $uptPath = self::normalizeRelativeJoin($base, '../htdocs/www/sdp');
        if (is_dir($uptPath)) {
            return $uptPath;
        }

        $devPath = self::normalizeRelativeJoin($base, '../102sdp/sdp');
        if (is_dir($devPath)) {
            return $devPath;
        }

        return $uptPath;
    }

    private static function normalizeRelativeJoin(string $base, string $relative): string
    {
        if (preg_match('/^([A-Za-z]:)(?:\/(.*))?$/', $base, $matches) === 1) {
            $prefix = $matches[1] . '/';
            $rest   = $matches[2] ?? '';
        } elseif (str_starts_with($base, '/')) {
            $prefix = '/';
            $rest   = ltrim($base, '/');
        } else {
            $prefix = '';
            $rest   = $base;
        }

        $segments = [];
        foreach (explode('/', $rest . '/' . $relative) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif ($prefix === '') {
                    $segments[] = '..';
                }
                continue;
            }
            $segments[] = $part;
        }

        return $prefix . implode('/', $segments);
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

        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $realFile = realpath($full);
        if ($realFile === false) {
            return $full;
        }

        $realFile = str_replace('\\', '/', $realFile);
        $isWithin = DIRECTORY_SEPARATOR === '\\'
            ? (strcasecmp($realFile, $realRoot) === 0 || stripos($realFile, $realRoot . '/') === 0)
            : ($realFile === $realRoot || str_starts_with($realFile, $realRoot . '/'));

        if (! $isWithin) {
            return null;
        }

        return $full;
    }
}
