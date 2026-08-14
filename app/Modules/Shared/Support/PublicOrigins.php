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

        return rtrim((string) env('app.baseURL', 'http://127.0.0.1:8081'), '/');
    }

    public static function legacyBase(): string
    {
        $host = self::requestHost();
        if (self::isPublicHost($host)) {
            return 'https://' . self::publicLegacyHost();
        }

        return rtrim((string) env('legacy.baseURL', 'http://127.0.0.1:8080'), '/');
    }

    public static function frontendBase(): string
    {
        $host = self::requestHost();
        if (self::isPublicHost($host)) {
            return 'https://' . self::publicFrontendHost();
        }

        return rtrim((string) env('frontend.origin', 'http://127.0.0.1:8082'), '/');
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
