<?php

namespace App\Modules\Dokumen\Repositories;

final class DokumenRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listByReference(string $jenisDokumen, string $idReference): array
    {
        return db_connect()->table('dokumen')
            ->where('JENIS_DOKUMEN', $jenisDokumen)
            ->where('ID_REFERENCE', $idReference)
            ->where('IS_DELETED', 0)
            ->orderBy('TGL_ENTRY', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $idDokumen): ?array
    {
        return db_connect()->table('dokumen')
            ->where('ID_DOKUMEN', $idDokumen)
            ->where('IS_DELETED', 0)
            ->get()
            ->getRowArray() ?: null;
    }

    public function nextId(string $prefix): string
    {
        $numberLength = 5;
        $row = db_connect()->table('dokumen')
            ->select('ID_DOKUMEN')
            ->like('ID_DOKUMEN', $prefix, 'after')
            ->where('LENGTH(ID_DOKUMEN)', strlen($prefix) + $numberLength, false)
            ->orderBy('ID_DOKUMEN', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $number = 1;
        if ($row !== null) {
            $number = (int) substr((string) $row['ID_DOKUMEN'], strlen($prefix)) + 1;
        }

        return $prefix . sprintf('%0' . $numberLength . 'd', $number);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): void
    {
        db_connect()->table('dokumen')->insert($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function update(string $idDokumen, array $row): void
    {
        db_connect()->table('dokumen')->where('ID_DOKUMEN', $idDokumen)->update($row);
    }

    public function softDelete(string $idDokumen): void
    {
        db_connect()->table('dokumen')
            ->where('ID_DOKUMEN', $idDokumen)
            ->update(['IS_DELETED' => 1, 'KONSOLIDASI' => 1]);
    }
}
