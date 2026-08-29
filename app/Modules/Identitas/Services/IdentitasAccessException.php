<?php

namespace App\Modules\Identitas\Services;

final class IdentitasAccessException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 403,
    ) {
        parent::__construct($message);
    }
}
