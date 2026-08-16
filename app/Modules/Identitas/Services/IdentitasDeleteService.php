<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Database\UnitOfWork;

final class IdentitasDeleteException extends \RuntimeException
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

final class IdentitasDeleteService
{
    private const PHOTO_COLUMNS = [
        'FOTO_DEPAN',
        'FOTO_KANAN',
        'FOTO_KIRI',
        'FOTO_CLOSEUP',
        'FOTO_CIRI_1',
        'FOTO_CIRI_2',
        'FOTO_CIRI_3',
    ];

    private const FINGERPRINT_COLUMNS = [
        'JEMPOL_KANAN',
        'TELUNJUK_KANAN',
        'TENGAH_KANAN',
        'MANIS_KANAN',
        'KELINGKING_KANAN',
        'JEMPOL_KIRI',
        'TELUNJUK_KIRI',
        'TENGAH_KIRI',
        'MANIS_KIRI',
        'KELINGKING_KIRI',
    ];

    public function __construct(
        private readonly IdentitasRepository $identitas,
        private readonly RbacService $rbac,
        private readonly UnitOfWork $uow,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * @return array{nomorInduk: string}
     */
    public function delete(string $nomorInduk, CurrentUser $user, string $grant): array
    {
        $access = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        if (! $access['canDelete']) {
            throw new IdentitasDeleteException(
                'Mohon maaf anda tidak berhak menghapus data identitas.',
                [],
                403,
            );
        }

        try {
            $approved = $this->auth->readHapusGrant($user, $nomorInduk, $grant);
        } catch (\RuntimeException $e) {
            throw new IdentitasDeleteException($e->getMessage(), [], 403);
        }

        $existing = $this->identitas->find($nomorInduk);
        if (! $existing) {
            throw new IdentitasDeleteException('Identitas tidak ditemukan.', [], 404);
        }

        if ($this->identitas->hasOpenPerkara($nomorInduk)) {
            throw new IdentitasDeleteException(
                'Mohon maaf data identitas tidak dapat dihapus karena masih memiliki data perkara, silahkan hapus perkara terlebih dahulu.',
                [],
                409,
            );
        }

        $sidik = $this->identitas->findSidikJari($nomorInduk);
        $logUser = $approved['supervisorId'];

        $this->uow->transactional(function () use ($nomorInduk, $logUser): void {
            $this->identitas->insertChangeLog([
                'NOMOR_INDUK' => $nomorInduk,
                'ID_PERKARA'  => '',
                'ID_USER'     => $logUser,
                'FIELD'       => '',
                'ISI_LAMA'    => '',
                'ISI_BARU'    => '',
                'TGL_UPDATE'  => date('Y-m-d H:i:s'),
                'NOREGGOL'    => '',
                'TABEL'       => 'identitas',
                'KETERANGAN'  => 'Hapus Identitas',
                'AKSI'        => 'DELETE',
                'IP'          => service('request')->getIPAddress(),
                'KONSOLIDASI' => '0',
            ]);
            $this->identitas->softDelete($nomorInduk);
        });

        $this->deletePhotos($existing, $sidik);

        return ['nomorInduk' => $nomorInduk];
    }

    /**
     * @param array<string, mixed>      $identitas
     * @param array<string, mixed>|null $sidik
     */
    private function deletePhotos(array $identitas, ?array $sidik): void
    {
        $root = rtrim((string) env('legacy.root', ''), '/');
        if ($root === '') {
            $root = dirname(__DIR__, 5) . '/102sdp/sdp';
        }

        foreach (self::PHOTO_COLUMNS as $column) {
            $this->unlinkStored($root, $identitas[$column] ?? null);
        }

        if ($sidik === null) {
            return;
        }

        foreach (self::FINGERPRINT_COLUMNS as $column) {
            $this->unlinkStored($root, $sidik[$column] ?? null);
        }
    }

    private function unlinkStored(string $root, mixed $path): void
    {
        $rel = trim((string) $path);
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }

        $full = str_starts_with($rel, '/') ? $rel : $root . '/' . ltrim($rel, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
