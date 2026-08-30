<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Database\UnitOfWork;
use App\Modules\Shared\Support\DateFormat;
use App\Modules\Shared\Support\PublicOrigins;

final class IdentitasCreateService
{
    public function __construct(
        private readonly IdentitasRepository $identitas = new IdentitasRepository(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly IdentitasWriteMapper $writer = new IdentitasWriteMapper(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{nomorInduk: string}
     */
    public function create(array $input, CurrentUser $user): array
    {
        // Pusat/Kanwil cannot create (RBAC also downgrades W→R; fail fast without DB).
        if ($user->isHq()) {
            throw new IdentitasCreateException(
                'Mohon maaf anda tidak berhak menambah data identitas.',
                [],
                403,
            );
        }

        $uptId = trim((string) ($user->uptId ?? ''));
        if ($uptId === '') {
            throw new IdentitasCreateException(
                'ID UPT wajib ada untuk menambah identitas.',
                ['uptId' => 'Login sebagai petugas UPT.'],
                403,
            );
        }

        $access = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        if (! $access['canWrite']) {
            throw new IdentitasCreateException(
                'Mohon maaf anda tidak berhak menambah data identitas.',
                [],
                403,
            );
        }

        $errors = $this->writer->validate($input, []);
        if ($errors !== []) {
            throw new IdentitasCreateException('Data identitas belum lengkap.', $errors);
        }

        $this->assertNoDuplicates($input);

        $pending = [];
        $nomorInduk = '';

        try {
            UnitOfWork::transactional(function () use ($input, $user, $uptId, &$pending, &$nomorInduk): void {
                $nomorInduk = $this->identitas->nextNomorInduk($uptId);
                $photos     = $this->writer->resolvePhotos($nomorInduk, $input, [], $pending);
                $row        = $this->writer->mapIdentitas($nomorInduk, $input, $photos, $user, true);
                $row['NAMA_LENGKAP'] = $this->writer->applyBinBinti(
                    (string) ($input['namaLengkap'] ?? ''),
                    (string) ($input['namaAyah'] ?? ''),
                    (string) ($input['jenisKelamin'] ?? ''),
                );
                $sidik = $this->writer->mapSidikJari($input);
                unset($sidik['ID_SIDIK_JARI']);

                $this->identitas->insert($row);
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

    /**
     * @param array<string, mixed> $input
     */
    private function assertNoDuplicates(array $input): void
    {
        $nama = $this->writer->str($input['namaLengkap'] ?? '');
        $ayah = $this->writer->str($input['namaAyah'] ?? '');
        $tgl  = DateFormat::fromDisplay($this->writer->str($input['tanggalLahir'] ?? ''));
        if ($nama === '' || $ayah === '' || $tgl === null) {
            return;
        }

        $wni = $this->writer->str($input['kewarganegaraan'] ?? 'WNI') === 'WNI';
        $tempatLahir = $wni ? $this->writer->str($input['tempatLahir'] ?? '') : null;
        if ($tempatLahir === '') {
            $tempatLahir = null;
        }

        $rows = $this->identitas->findCreateDuplicates($nama, $ayah, $tgl, $tempatLahir);
        if ($rows === []) {
            return;
        }

        $frontend = PublicOrigins::frontendBase();
        $matches  = [];
        foreach ($rows as $row) {
            $noin = (string) ($row['NOMOR_INDUK'] ?? '');
            $matches[] = [
                'nomorInduk'  => $noin,
                'namaLengkap' => $row['NAMA_LENGKAP'] ?? null,
                'tanggalLahir'=> DateFormat::toDisplay($row['TANGGAL_LAHIR'] ?? null, '/') ?? null,
                'tempatLahir' => $row['tempatLahir'] ?? null,
                'namaIbu'     => $row['NM_IBU'] ?? null,
                'jenisKelamin'=> $row['ID_JENIS_KELAMIN'] ?? null,
                'href'        => $noin !== '' ? $frontend . '/identitas/' . rawurlencode($noin) : null,
            ];
        }

        throw new IdentitasCreateException(
            'Ditemukan identitas yang mirip. Periksa daftar berikut sebelum menambah lagi.',
            [],
            409,
            $matches,
        );
    }

    private function saveKemiripan(string $nomorInduk, string $similarCsv, CurrentUser $user): void
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', $similarCsv)), static fn ($id) => $id !== ''));
        $this->identitas->replaceKemiripan($nomorInduk, $ids, $user->uptId);
    }
}
