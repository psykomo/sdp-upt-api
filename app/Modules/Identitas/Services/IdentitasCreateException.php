<?php

namespace App\Modules\Identitas\Services;

final class IdentitasCreateException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     * @param list<array<string, mixed>> $matches
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly array $matches = [],
    ) {
        parent::__construct($message);
    }
}
