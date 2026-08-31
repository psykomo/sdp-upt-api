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
        $this->guardIdentitas(fn () => $this->identitas->assertNomorIndukAccessible($nomorInduk, $user));
    }

    public function resolveStorageUptId(string $nomorInduk, CurrentUser $user): string
    {
        $uptId = trim((string) ($user->uptId ?? ''));
        if ($uptId !== '') {
            return $uptId;
        }

        $fallback = trim((string) ($this->identitas->parentPerkaraUptId($nomorInduk) ?? ''));

        return $fallback !== '' ? $fallback : '000';
    }

    /**
     * @param array<string, mixed> $row
     */
    public function assertRowAccessible(array $row, CurrentUser $user): void
    {
        $jenis = (string) ($row['JENIS_DOKUMEN'] ?? '');
        $reference = trim((string) ($row['ID_REFERENCE'] ?? ''));

        if ($jenis === 'identitas' && $reference !== '') {
            $this->guardIdentitas(fn () => $this->identitas->assertNomorIndukAccessible($reference, $user));

            return;
        }

        throw new DokumenException('Mohon maaf anda tidak berhak mengakses dokumen ini.', [], 403);
    }

    /**
     * @param callable(): void $action
     */
    private function guardIdentitas(callable $action): void
    {
        try {
            $action();
        } catch (IdentitasAccessException $e) {
            throw new DokumenException($e->getMessage(), $e->errors, $e->status);
        }
    }
}
