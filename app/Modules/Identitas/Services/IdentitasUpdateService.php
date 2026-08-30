<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Database\UnitOfWork;

final class IdentitasUpdateService
{
    public function __construct(
        private readonly IdentitasRepository $identitas = new IdentitasRepository(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly IdentitasAccessService $access = new IdentitasAccessService(),
        private readonly IdentitasSensitiveFieldService $sensitive = new IdentitasSensitiveFieldService(),
        private readonly IdentitasWriteMapper $writer = new IdentitasWriteMapper(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{nomorInduk: string}
     */
    public function update(string $nomorInduk, array $input, CurrentUser $user): array
    {
        $access = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        if (! $access['canWrite']) {
            throw new IdentitasUpdateException(
                'Mohon maaf anda tidak berhak mengubah data identitas.',
                [],
                403,
            );
        }

        $this->access->assertNomorIndukAccessible($nomorInduk, $user);

        $existing = $this->identitas->find($nomorInduk);

        if (! $existing) {
            throw new IdentitasUpdateException('Identitas tidak ditemukan.', [], 404);
        }

        $errors = $this->writer->validate($input, $existing);
        $errors = array_merge(
            $errors,
            $this->sensitive->validateChanges($input, $existing, $nomorInduk, $user),
        );
        if ($errors !== []) {
            throw new IdentitasUpdateException('Data identitas belum lengkap.', $errors);
        }

        $pending = [];
        try {
            $photos = $this->writer->resolvePhotos($nomorInduk, $input, $existing, $pending);
            $row    = $this->writer->mapIdentitas($nomorInduk, $input, $photos, $user, false);
            $sidik  = $this->writer->mapSidikJari($input);

            UnitOfWork::transactional(function () use ($nomorInduk, $existing, $row, $sidik, $input, $user): void {
                $this->writeChangeLog($nomorInduk, $existing, $row, $user);
                $this->identitas->update($nomorInduk, $row);
                $this->identitas->upsertSidikJari($nomorInduk, $sidik);
                $this->saveKemiripan($nomorInduk, (string) ($input['nomorIndukSimilar'] ?? ''), $user);
            });
        } catch (\Throwable $e) {
            $this->writer->discardPendingPhotos($pending);
            throw $e;
        }

        $this->writer->commitPendingPhotos($pending);

        return ['nomorInduk' => $nomorInduk];
    }

    private function saveKemiripan(string $nomorInduk, string $similarCsv, CurrentUser $user): void
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', $similarCsv)), static fn ($id) => $id !== ''));
        $this->identitas->replaceKemiripan($nomorInduk, $ids, $user->uptId);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $baru
     */
    private function writeChangeLog(string $nomorInduk, array $existing, array $baru, CurrentUser $user): void
    {
        $skip = ['ID_USER', 'KONSOLIDASI', 'TGL_ENTRY', 'KONSOLIDASI_IMAGE'];
        $now  = date('Y-m-d H:i:s');

        foreach ($baru as $field => $value) {
            if (in_array($field, $skip, true) || ! array_key_exists($field, $existing)) {
                continue;
            }

            $lama = (string) ($existing[$field] ?? '');
            $baruStr = $value === null ? '' : (string) $value;
            if ($lama === '0000-00-00' && $baruStr === '') {
                continue;
            }
            if ($lama === $baruStr) {
                continue;
            }

            $this->identitas->insertChangeLog([
                'NOMOR_INDUK' => $nomorInduk,
                'ID_PERKARA'  => '',
                'ID_USER'     => $user->id,
                'FIELD'       => $field,
                'ISI_LAMA'    => $lama,
                'ISI_BARU'    => $baruStr,
                'TGL_UPDATE'  => $now,
                'NOREGGOL'    => '',
                'TABEL'       => 'identitas',
                'AKSI'        => 'UPDATE',
                'IP'          => service('request')->getIPAddress(),
                'KONSOLIDASI' => '0',
            ]);
        }
    }
}
