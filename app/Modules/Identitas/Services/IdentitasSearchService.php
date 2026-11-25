<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\DTO\IdentitasSearchQuery;
use App\Modules\Identitas\Repositories\IdentitasSearchRepository;
use App\Modules\Identitas\Support\IdentitasPhotoResolver;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Support\DateFormat;
use App\Modules\Shared\Support\PublicOrigins;

final class IdentitasSearchService
{
    public function __construct(
        private readonly IdentitasSearchRepository $repository = new IdentitasSearchRepository(),
        private readonly IdentitasCaseViewService $caseView = new IdentitasCaseViewService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly IdentitasPhotoResolver $photos = new IdentitasPhotoResolver(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(IdentitasSearchQuery $query, CurrentUser $user): array
    {
        $access   = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        $found    = $this->repository->search($query, $user);
        $legacy   = PublicOrigins::legacyBase();
        $frontend = PublicOrigins::frontendBase();

        $items = [];
        foreach ($found['rows'] as $row) {
            $noin = (string) $row['NOMOR_INDUK'];
            $item = [
                'nomorInduk'      => $noin,
                'nomorRegistrasi' => $row['NMR_REG_GOL'] ?? null,
                'tanggalMasukUpt' => DateFormat::toDisplay($row['TGL_MSK_LAPAS'] ?? null, '-'),
                'namaLengkap'     => $row['NAMA_LENGKAP'] ?? null,
                'tanggalLahir'    => DateFormat::toDisplay($row['TANGGAL_LAHIR'] ?? null, '/'),
                'alamat'          => $row['ALAMAT_WBP'] ?? $row['ALAMAT'] ?? null,
                'agama'           => $row['agama'] ?? null,
                'asalUpt'         => $row['URAIAN'] ?? null,
                'verifikasi'      => ((string) ($row['konsol'] ?? '0') === '0') ? 'Belum' : 'Sudah',
                'jenisKelamin'    => $row['ID_JENIS_KELAMIN'] ?? null,
                'wargaNegara'     => $row['ID_JENIS_WARGANEGARA'] ?? null,
                'isTahanan'       => (int) ($row['tahanan'] ?? 0) === 1,
                'fotoDepanUrl'    => $this->photoUrl($legacy, $row, $noin, $user),
                'links'           => [
                    'lihat'        => $frontend . '/identitas/' . rawurlencode($noin),
                    'ubah'         => $frontend . '/identitas/' . rawurlencode($noin) . '/ubah',
                    'hapus'        => $frontend . '/identitas/' . rawurlencode($noin) . '/hapus',
                    'cetakIdentitas' => $legacy . '/LaporanIdentitas/cetak_identitas_napi/' . rawurlencode($noin),
                    'cetakTahanan'   => $legacy . '/LaporanIdentitas/cetak_identitas_tahanan/' . rawurlencode($noin),
                    'cetakSidikJari' => $legacy . '/DaftarSidikJari/cetak_sidikjari/' . rawurlencode($noin),
                    'tambah'         => $frontend . '/identitas/baru',
                ],
            ];

            if ($query->includeCase) {
                $item['case'] = $this->caseView->forNomorInduk($noin);
            }

            $items[] = $item;
        }

        return [
            'items'      => $items,
            'pagination' => [
                'page'    => $query->page,
                'perPage' => $query->perPage,
                'total'   => $found['total'],
            ],
            'query'  => [
                'field'              => $query->field,
                'q'                  => $query->q,
                'photoStatus'        => $query->photoStatus,
                'fingerprintStatus'  => $query->fingerprintStatus,
                'activeOnly'         => $query->activeOnly,
                'recidivistOnly'     => $query->recidivistOnly,
                'mode'               => $query->mode,
                'sort'               => $query->sort,
                'order'              => $query->order,
            ],
            'access'     => $access,
            'legacyBase' => $legacy,
            'links'      => [
                'tambah' => $frontend . '/identitas/baru',
            ],
            'columns' => $user->isHq()
                ? ['nomorInduk', 'nomorRegistrasi', 'tanggalMasukUpt', 'namaLengkap', 'tanggalLahir', 'alamat', 'asalUpt', 'verifikasi']
                : ['nomorInduk', 'nomorRegistrasi', 'tanggalMasukUpt', 'namaLengkap', 'tanggalLahir', 'alamat', 'agama', 'verifikasi'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function photoUrl(string $legacy, array $row, string $nomorInduk, CurrentUser $user): ?string
    {
        $path = $this->photos->resolvePath($row, 'FOTO_DEPAN', $nomorInduk, $user);
        if ($path === null || trim($path) === '' || ! str_contains($path, 'upload')) {
            return null;
        }

        return $legacy . '/' . ltrim($path, '/');
    }
}
