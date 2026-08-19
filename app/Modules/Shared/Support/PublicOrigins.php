<?php

namespace App\Modules\Shared\Support;

final class PublicOrigins
{
    public static function apiBase(): string
    {
        $host = self::requestHost();
        if (self::isPublicHost($host)) {
            return 'https://' . self::publicApiHost();
        }

        // API is never advertised on the LAN. Officers use same-origin /api.
        return rtrim((string) env('app.baseURL', 'http://127.0.0.1:8081'), '/');
    }

    public static function legacyBase(): string
    {
        $host = self::requestHost();
        if (self::isPublicHost($host)) {
            return 'https://' . self::publicLegacyHost();
        }

        return self::rewriteOfficerHost(
            rtrim((string) env('legacy.baseURL', 'http://127.0.0.1:8080'), '/'),
            $host,
        );
    }

    public static function frontendBase(): string
    {
        $host = self::requestHost();
        if (self::isPublicHost($host)) {
            return 'https://' . self::publicFrontendHost();
        }

        return self::rewriteOfficerHost(
            rtrim((string) env('frontend.origin', 'http://127.0.0.1:8082'), '/'),
            $host,
        );
    }

    private static function requestHost(): string
    {
        $request = service('request');
        $candidates = [
            $request->getHeaderLine('X-SDP-Public-Host'),
            $request->getHeaderLine('X-Forwarded-Host'),
            $request->getServer('HTTP_HOST') ?? '',
        ];

        foreach ($candidates as $raw) {
            $host = strtolower(trim(explode(',', (string) $raw)[0]));
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
            if ($host !== '') {
                return $host;
            }
        }

        return '127.0.0.1';
    }

    private static function isLoopback(string $host): bool
    {
        return $host === '127.0.0.1' || $host === 'localhost' || $host === '::1';
    }

    /**
     * When the officer opened a LAN host, leftover 8080/8082 links must use that
     * host — not 127.0.0.1 (that would be the officer PC).
     */
    private static function rewriteOfficerHost(string $default, string $host): string
    {
        if ($host === '' || self::isLoopback($host)) {
            return $default;
        }

        $parts  = parse_url($default);
        $scheme = $parts['scheme'] ?? 'http';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private static function isPublicHost(string $host): bool
    {
        return in_array($host, array_filter([
            self::publicLegacyHost(),
            self::publicApiHost(),
            self::publicFrontendHost(),
        ]), true);
    }

    private static function publicLegacyHost(): string
    {
        return strtolower((string) env('public.legacyHost', 'sdp.caripasal.com'));
    }

    private static function publicApiHost(): string
    {
        return strtolower((string) env('public.apiHost', 'sdp-api.caripasal.com'));
    }

    private static function publicFrontendHost(): string
    {
        return strtolower((string) env('public.frontendHost', 'sdp-front.caripasal.com'));
    }
}
