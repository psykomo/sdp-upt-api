<?php

namespace App\Modules\Dokumen\Services;

use App\Modules\Identitas\Services\IdentitasAccessException;
use App\Modules\Identitas\Services\IdentitasAccessService;
use App\Modules\Shared\Auth\CurrentUser;

final class DokumenAccessService
{
    public function __construct(
        private readonly IdentitasAccessService $identitas = new IdentitasAccessService(),
    ) {
    }

    public function assertIdentitasNomorInduk(string $nomorInduk, CurrentUser $user): void
    {
        $this->identitas->assertNomorIndukAccessible($nomorInduk, $user);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function assertRowAccessible(array $row, CurrentUser $user): void
    {
        $jenis = (string) ($row['JENIS_DOKUMEN'] ?? '');
        $reference = trim((string) ($row['ID_REFERENCE'] ?? ''));

        if ($jenis === 'identitas' && $reference !== '') {
            $this->identitas->assertNomorIndukAccessible($reference, $user);

            return;
        }

        throw new IdentitasAccessException('Mohon maaf anda tidak berhak mengakses dokumen ini.');
    }
}
