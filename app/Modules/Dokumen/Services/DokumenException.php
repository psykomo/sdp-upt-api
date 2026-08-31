<?php

namespace App\Modules\Dokumen\Services;

final class DokumenException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
