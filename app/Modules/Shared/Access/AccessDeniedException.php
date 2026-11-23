<?php

namespace App\Modules\Shared\Access;

class AccessDeniedException extends \RuntimeException
{
    public const DEFAULT_MESSAGE = 'Mohon maaf anda tidak berhak mengakses data ini.';

    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message = self::DEFAULT_MESSAGE,
        public readonly array $errors = [],
        public readonly int $status = 403,
    ) {
        parent::__construct($message, $status);
    }
}
