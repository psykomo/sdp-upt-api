<?php

namespace App\Modules\Identity\Services;

use App\Modules\Shared\Support\DateFormat;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final class IdentityCaseViewService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Port of identitas_perkara_model::getPerkaraModeView.
     *
     * @return array<string, mixed>
     */
    public function forNomorInduk(string $nomorInduk): array
    {
        $empty = [
            'nomorBerkas'             => '-',
            'nomorRegistrasi'         => '-',
            'jenisRegistrasi'         => '-',
            'kejahatan'               => '-',
            'tglMulaiDitahan'         => '-',
            'tglEkspirasi'            => '-',
            'sisaPidana'              => '-',
            'tanggalMasukLapas'       => '-',
            'tempatLokasiSel'         => '',
            'statusPenghuni'          => '',
            'statusSubPenghuni'       => '',
        ];

        $perkara = $this->db->table('perkara')
            ->select('perkara.ID_PERKARA, perkara.LOKASI_SEL, perkara.NMR_REG_GOL, jr.DESKRIPSI AS jenis, perkara.ID_REG, perkara.ID_STATUS, perkara.ID_SUB_STATUS, perkara.TGL_PERTAMA_DITAHAN, perkara.TGL_EKSPIRASI, perkara.TGL_MSK_LAPAS, jr.IS_TAHANAN, sel.NO_SEL')
            ->join('jenis_registrasi jr', 'jr.ID_REG = perkara.ID_REG', 'left')
            ->join('sel', 'perkara.LOKASI_SEL = sel.ID_SEL', 'left')
            ->where('perkara.NOMOR_INDUK', $nomorInduk)
            ->where('perkara.IS_DELETE', 0)
            ->get()
            ->getRowArray();

        if (! $perkara) {
            return $empty;
        }

        $kejahatan = $this->kejahatanUtama((string) $perkara['ID_PERKARA'], (string) $perkara['NMR_REG_GOL']);
        $sel       = trim((string) ($perkara['NO_SEL'] ?: $perkara['LOKASI_SEL'] ?: ''));

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
            'statusPenghuni'    => $this->deskripsi($perkara['ID_STATUS'] ?? null),
            'statusSubPenghuni' => $this->deskripsi($perkara['ID_SUB_STATUS'] ?? null),
        ];
    }

    private function kejahatanUtama(string $idPerkara, string $nmrRegGol): string
    {
        $row = $this->db->table('kejahatan k')
            ->select('t.TERMINOLOGI')
            ->join('terminologi t', 'k.ID_TERMINOLOGI = t.ID_TERMINOLOGI')
            ->where('k.ID_PERKARA', $idPerkara)
            ->like('k.NOREGGOL', $nmrRegGol)
            ->where('k.IS_KEJAHATAN_UTAMA', '1')
            ->where('k.IS_DELETED', '0')
            ->get()
            ->getRowArray();

        return $row['TERMINOLOGI'] ?? 'kosong';
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

    private function deskripsi(?string $idLookup): string
    {
        if ($idLookup === null || $idLookup === '') {
            return '';
        }

        $row = $this->db->table('daftar_referensi')
            ->select('DESKRIPSI')
            ->where('ID_LOOKUP', $idLookup)
            ->get()
            ->getRowArray();

        return (string) ($row['DESKRIPSI'] ?? '');
    }
}
