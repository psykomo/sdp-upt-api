<?php

namespace App\Modules\Shared\Support;

use CodeIgniter\Database\BaseConnection;

final class DeskripsiLookup
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function referensi(?string $id): string
    {
        return $this->label('daftar_referensi', 'ID_LOOKUP', $id);
    }

    public function suku(?string $id): string
    {
        return $this->label('suku', 'ID_SUKU', $id);
    }

    public function pekerjaan(?string $id): string
    {
        return $this->label('pekerjaan', 'ID_PEKERJAAN', $id);
    }

    public function propinsi(?string $id): string
    {
        return $this->label('propinsi', 'ID_PROPINSI', $id);
    }

    public function dati2(?string $id): string
    {
        return $this->label('dati2', 'ID_DATI2', $id);
    }

    public function negara(?string $id): string
    {
        return $this->label('daftar_negara', 'ID_NEGARA', $id, 'NAMA_NEGARA');
    }

    public function label(string $table, string $idColumn, ?string $id, string $textColumn = 'DESKRIPSI'): string
    {
        if ($id === null || trim($id) === '') {
            return '';
        }

        $row = $this->db->table($table)
            ->select($textColumn)
            ->where($idColumn, $id)
            ->get()
            ->getRowArray();

        return (string) ($row[$textColumn] ?? '');
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function referensiGroup(string $group): array
    {
        return $this->items('daftar_referensi', 'ID_LOOKUP', 'DESKRIPSI', ['GROUPS' => $group]);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function sukuItems(): array
    {
        return $this->items('suku', 'ID_SUKU', 'DESKRIPSI');
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function pekerjaanItems(): array
    {
        return $this->items('pekerjaan', 'ID_PEKERJAAN', 'DESKRIPSI');
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function propinsiItems(): array
    {
        return $this->items('propinsi', 'ID_PROPINSI', 'DESKRIPSI');
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function negaraItems(): array
    {
        return $this->items('daftar_negara', 'ID_NEGARA', 'NAMA_NEGARA');
    }

    /**
     * @return list<array{id: string, label: string, propinsiId: string}>
     */
    public function dati2Items(?string $propinsiId = null): array
    {
        $builder = $this->db->table('dati2')
            ->select('ID_DATI2 AS id, DESKRIPSI AS label, ID_PROPINSI AS propinsiId')
            ->orderBy('DESKRIPSI', 'ASC');
        if ($propinsiId !== null && trim($propinsiId) !== '') {
            $builder->where('ID_PROPINSI', $propinsiId);
        }

        $rows = $builder->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['id']         = (string) ($row['id'] ?? '');
            $row['label']      = (string) ($row['label'] ?? '');
            $row['propinsiId'] = (string) ($row['propinsiId'] ?? '');
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, string> $where
     * @return list<array{id: string, label: string}>
     */
    private function items(string $table, string $idColumn, string $textColumn, array $where = []): array
    {
        $builder = $this->db->table($table)
            ->select("{$idColumn} AS id, {$textColumn} AS label")
            ->orderBy($textColumn, 'ASC');
        foreach ($where as $column => $value) {
            $builder->where($column, $value);
        }

        $rows = $builder->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['id']    = (string) ($row['id'] ?? '');
            $row['label'] = (string) ($row['label'] ?? '');
        }
        unset($row);

        return $rows;
    }
}
