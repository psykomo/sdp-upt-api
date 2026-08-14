<?php

namespace App\Modules\Shared\Http;

use CodeIgniter\HTTP\ResponseInterface;

final class ApiResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data = [], int $status = 200): ResponseInterface
    {
        return self::json([
            'ok'   => true,
            'data' => $data,
        ], $status);
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function fail(string $message, int $status = 400, array $errors = []): ResponseInterface
    {
        $payload = [
            'ok'      => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return self::json($payload, $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): ResponseInterface
    {
        return service('response')
            ->setStatusCode($status)
            ->setJSON($payload);
    }
}
