<?php

namespace App\Modules\Auth\Services;

use App\Modules\Shared\Auth\CurrentUser;
use CodeIgniter\Database\BaseConnection;

final class SiteContextService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Mirrors Sessionnative UPT bootstrap: daftar_referensi ID_LOOKUP=001 holds the current UPT id.
     *
     * @return array{isPusat: bool, isKanwil: bool, uptId: ?string, uptName: ?string}
     */
    public function resolve(): array
    {
        $row = $this->db->table('daftar_referensi')
            ->select('DESKRIPSI')
            ->where('ID_LOOKUP', '001')
            ->get()
            ->getRowArray();

        $id = $row['DESKRIPSI'] ?? '';

        if ($id === '' || $id === null) {
            return [
                'isPusat'  => true,
                'isKanwil' => false,
                'uptId'    => null,
                'uptName'  => 'Direktorat Jenderal Pemasyarakatan',
            ];
        }

        $kanwil = $this->db->table('kanwil')
            ->select('KODE, URAIAN')
            ->where('KODE', $id)
            ->get()
            ->getRowArray();

        if ($kanwil) {
            return [
                'isPusat'  => false,
                'isKanwil' => true,
                'uptId'    => $id,
                'uptName'  => 'Kantor Wilayah ' . ($kanwil['URAIAN'] ?? $id),
            ];
        }

        $upt = $this->db->table('upt')
            ->select('ID_UPT, URAIAN')
            ->where('ID_UPT', $id)
            ->get()
            ->getRowArray();

        return [
            'isPusat'  => false,
            'isKanwil' => false,
            'uptId'    => $id,
            'uptName'  => $upt['URAIAN'] ?? $id,
        ];
    }

    public function hydrateUser(array $pengguna): CurrentUser
    {
        $site = $this->resolve();

        return new CurrentUser(
            id: (string) $pengguna['ID_USER'],
            role: (string) $pengguna['ID_ROLE'],
            name: (string) ($pengguna['NAMA'] ?? $pengguna['ID_USER']),
            level: (string) ($pengguna['LEVEL'] ?? ''),
            isPusat: $site['isPusat'],
            isKanwil: $site['isKanwil'],
            uptId: $site['uptId'],
            uptName: $site['uptName'],
            claims: $pengguna,
        );
    }
}
