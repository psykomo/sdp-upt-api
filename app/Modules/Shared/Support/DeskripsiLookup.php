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
}
