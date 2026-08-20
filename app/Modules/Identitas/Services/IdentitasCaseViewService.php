<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Shared\Support\DateFormat;
use App\Modules\Shared\Support\DeskripsiLookup;
use DateTimeImmutable;

final class IdentitasCaseViewService
{
    public function __construct(
        private readonly IdentitasRepository $identitas = new IdentitasRepository(),
        private readonly DeskripsiLookup $lookup = new DeskripsiLookup(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forNomorInduk(string $nomorInduk): array
    {
        $empty = [
            'nomorBerkas'       => '-',
            'nomorRegistrasi'   => '-',
            'jenisRegistrasi'   => '-',
            'kejahatan'         => '-',
            'tglMulaiDitahan'   => '-',
            'tglEkspirasi'      => '-',
            'sisaPidana'        => '-',
            'tanggalMasukLapas' => '-',
            'tempatLokasiSel'   => '',
            'statusPenghuni'    => '',
            'statusSubPenghuni' => '',
        ];

        $perkara = $this->identitas->caseViewRow($nomorInduk);
        if (! $perkara) {
            return $empty;
        }

        $kejahatan = $this->identitas->kejahatanUtamaLabel(
            (string) $perkara['ID_PERKARA'],
            (string) $perkara['NMR_REG_GOL'],
        );
        $sel = trim((string) ($perkara['NO_SEL'] ?: $perkara['LOKASI_SEL'] ?: ''));

        return [
            'nomorBerkas'       => $perkara['ID_PERKARA'] ?: '-',
            'nomorRegistrasi'   => $perkara['NMR_REG_GOL'] ?: '-',
            'jenisRegistrasi'   => $perkara['jenis'] ?: '-',
            'kejahatan'         => $kejahatan,
            'tglMulaiDitahan'   => DateFormat::toDisplay($perkara['TGL_PERTAMA_DITAHAN']) ?? '-',
            'tglEkspirasi'      => DateFormat::toDisplay($perkara['TGL_EKSPIRASI']) ?? '-',
            'sisaPidana'        => $this->sisaPidana($perkara),
            'tanggalMasukLapas' => DateFormat::toDisplay($perkara['TGL_MSK_LAPAS']) ?? '-',
            'tempatLokasiSel'   => $sel,
            'statusPenghuni'    => $this->lookup->referensi($perkara['ID_STATUS'] ?? null),
            'statusSubPenghuni' => $this->lookup->referensi($perkara['ID_SUB_STATUS'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $perkara
     */
    private function sisaPidana(array $perkara): string
    {
        $idReg = (string) ($perkara['ID_REG'] ?? '');
        if ($idReg === 'SH') {
            return 'Seumur Hidup';
        }
        if ($idReg === 'PM') {
            return '-';
        }

        $exp = (string) ($perkara['TGL_EKSPIRASI'] ?? '');
        if ($exp === '' || $exp === '0000-00-00') {
            return '-';
        }

        try {
            $now    = new DateTimeImmutable('today');
            $expiry = new DateTimeImmutable(substr($exp, 0, 10));
        } catch (\Exception) {
            return '-';
        }

        if ($expiry < $now) {
            if (($perkara['ID_STATUS'] ?? '') === 'STTA') {
                return 'Sudah Bebas';
            }

            return ((int) ($perkara['IS_TAHANAN'] ?? 0) === 0)
                ? 'Habis Masa Pidana'
                : 'Habis Masa Penahanan';
        }

        $diff = $now->diff($expiry);

        return $diff->y . ' tahun, ' . $diff->m . ' bulan, ' . $diff->d . ' hari';
    }
}
