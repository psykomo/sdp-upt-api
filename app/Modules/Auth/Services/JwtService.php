<?php

namespace App\Modules\Auth\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

final class JwtService
{
    public const TYP_SESSION      = 'session';
    public const TYP_HANDOVER     = 'handover';
    public const TYP_SUPERVISOR   = 'supervisor-grant';
    public const TYP_HAPUS_LEGACY = 'hapus-supervisor';

    public function encode(array $claims): string
    {
        $ttlHours = (int) env('jwt.ttlHours', 8);

        return $this->encodeWithTtl(
            array_merge(['typ' => self::TYP_SESSION], $claims),
            $ttlHours * 3600,
        );
    }

    public function encodeWithTtl(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + max(1, $ttlSeconds),
            'iss' => 'sdp-api',
        ]);

        return JWT::encode($payload, $this->secret(), 'HS256');
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), 'HS256'));
        } catch (UnexpectedValueException $e) {
            throw new \RuntimeException('Token tidak valid atau kedaluwarsa.', 401, $e);
        }

        return (array) $decoded;
    }

    private function secret(): string
    {
        $secret = (string) env('jwt.secret', '');
        if ($secret === '') {
            throw new \RuntimeException('jwt.secret is not configured.');
        }

        return $secret;
    }
}
