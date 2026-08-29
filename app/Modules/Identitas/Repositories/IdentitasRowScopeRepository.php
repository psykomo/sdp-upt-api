<?php

namespace App\Modules\Identitas\Repositories;

interface IdentitasRowScopeRepository
{
    /**
     * @return array{idUpt: string, kanwil: string}|null
     */
    public function activeStaParentPerkaraContext(string $nomorInduk): ?array;
}
