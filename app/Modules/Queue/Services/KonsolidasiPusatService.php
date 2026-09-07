<?php

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Repositories\KonsolidasiPusatRepository;

/**
 * Thin service layer untuk operasi changescompress sebagai Pusat.
 * Semua logika ada di Repository; service hanya menjadi orchestrator.
 */
final class KonsolidasiPusatService
{
    private KonsolidasiPusatRepository $repository;

    public function __construct()
    {
        $this->repository = new KonsolidasiPusatRepository();
    }

    /**
     * Buat paket perubahan data untuk UPT dan kembalikan sebagai base64.
     *
     * @param  string   $idUpt
     * @param  string[] $types  e.g. ['data', 'dokumen']
     * @return array<string, mixed>
     */
    public function getChangesForUpt(string $idUpt, array $types): array
    {
        return $this->repository->buildChangesForUpt($idUpt, $types);
    }

    /**
     * Buat paket perubahan data untuk Kanwil.
     */
    public function getChangesForKanwil(string $idKanwil, array $types): array
    {
        return $this->repository->buildChangesForKanwil($idKanwil, $types);
    }

    /**
     * Tandai tabel sudah di-restore oleh UPT.
     */
    public function markReadedUpt(string $idUpt, string $tableName, string $tempPath): void
    {
        $this->repository->markReaded($idUpt, true, $tableName, $tempPath);
    }

    /**
     * Tandai tabel sudah di-restore oleh Kanwil.
     */
    public function markReadedKanwil(string $idKanwil, string $tableName, string $tempPath): void
    {
        $this->repository->markReaded($idKanwil, false, $tableName, $tempPath);
    }

    /**
     * Hapus folder temp setelah restore selesai.
     */
    public function clearTemp(string $tempPath): void
    {
        $this->repository->clearTemp($tempPath);
    }
}
