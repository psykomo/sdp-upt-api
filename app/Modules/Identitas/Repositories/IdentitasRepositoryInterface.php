<?php

namespace App\Modules\Identitas\Repositories;

interface IdentitasRepositoryInterface extends IdentitasRowScopeRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function mutasiPhotoRows(string $nomorInduk): array;

    public function activeStaParentPerkaraId(string $nomorInduk): ?string;

    public function activeStaParentUptId(string $nomorInduk): ?string;

    public function hasProcessedRemisi(string $idPerkara): bool;

    public function hasSidangTppAkses002(string $idPerkara, string $idUpt): bool;
}
