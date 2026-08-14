<?php

namespace App\Modules\Auth\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

final class JwtService
{
    public function encode(array $claims): string
    {
        $now = time();
        $ttlHours = (int) env('jwt.ttlHours', 8);

        $payload = array_merge($claims, [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttlHours * 3600),
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
