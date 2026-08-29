<?php

namespace App\Modules\Auth\Services;

use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Tenancy\TenantContext;

final class SiteContextService
{
    /**
     * Mirrors Sessionnative UPT bootstrap: daftar_referensi ID_LOOKUP=001 holds the current UPT id.
     *
     * @return array{isPusat: bool, isKanwil: bool, uptId: ?string, uptName: ?string}
     */
    public function resolve(): array
    {
        $row = db_connect()->table('daftar_referensi')
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

        $kanwil = db_connect()->table('kanwil')
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

        $upt = db_connect()->table('upt')
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

    public function hydrateUser(array $pengguna, ?string $workingUptId = null): CurrentUser
    {
        $site = $this->resolve();
        $workingUptId = TenantContext::normalize($workingUptId);

        // Merged Kanwil on national (Login.php process): Pusat boot + pengguna.KANWIL set.
        $kanwilCode = trim((string) ($pengguna['KANWIL'] ?? ''));
        if ($site['isPusat'] && $kanwilCode !== '' && $kanwilCode !== '0') {
            $kanwil = db_connect()->table('kanwil')
                ->select('KODE, URAIAN')
                ->where('KODE', $kanwilCode)
                ->get()
                ->getRowArray();

            return new CurrentUser(
                id: (string) $pengguna['ID_USER'],
                role: (string) $pengguna['ID_ROLE'],
                name: (string) ($pengguna['NAMA'] ?? $pengguna['ID_USER']),
                level: (string) ($pengguna['LEVEL'] ?? ''),
                isPusat: false,
                isKanwil: true,
                uptId: $kanwilCode,
                uptName: 'Kantor Wilayah ' . ($kanwil['URAIAN'] ?? $kanwilCode),
                claims: $pengguna,
            );
        }

        // National shared_database: optional working UPT at login (legacy upt_session).
        if ($site['isPusat'] && $workingUptId !== null) {
            $upt = db_connect()->table('upt')
                ->select('ID_UPT, URAIAN')
                ->where('ID_UPT', $workingUptId)
                ->get()
                ->getRowArray();

            if ($upt === null) {
                throw new \RuntimeException('ID UPT tidak dikenali.', 422);
            }

            return new CurrentUser(
                id: (string) $pengguna['ID_USER'],
                role: (string) $pengguna['ID_ROLE'],
                name: (string) ($pengguna['NAMA'] ?? $pengguna['ID_USER']),
                level: (string) ($pengguna['LEVEL'] ?? ''),
                isPusat: false,
                isKanwil: false,
                uptId: (string) $upt['ID_UPT'],
                uptName: (string) ($upt['URAIAN'] ?? $upt['ID_UPT']),
                claims: $pengguna,
            );
        }

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
