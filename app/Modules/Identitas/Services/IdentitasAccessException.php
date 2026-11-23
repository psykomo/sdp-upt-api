<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Shared\Access\AccessDeniedException;

final class IdentitasAccessException extends AccessDeniedException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message,
        array $errors = [],
        int $status = 403,
    ) {
        parent::__construct($message, $errors, $status);
    }
}
