<?php

namespace App\Modules\Shared\Auth;

final class CurrentUser
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly string $name,
        public readonly string $level,
        public readonly bool $isPusat,
        public readonly bool $isKanwil,
        public readonly ?string $uptId,
        public readonly ?string $uptName,
        public readonly array $claims = [],
    ) {
    }

    public function isHq(): bool
    {
        return $this->isPusat || $this->isKanwil;
    }
}
