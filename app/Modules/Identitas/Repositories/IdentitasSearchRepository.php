<?php

namespace App\Modules\Identitas\Repositories;

use App\Modules\Identitas\DTO\IdentitasSearchQuery;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Support\DateFormat;
use CodeIgniter\Database\BaseBuilder;

final class IdentitasSearchRepository
{
    private const FINGERPRINT_COLS = [
        'JEMPOL_KIRI', 'JEMPOL_KANAN',
        'TELUNJUK_KIRI', 'TELUNJUK_KANAN',
        'TENGAH_KIRI', 'TENGAH_KANAN',
        'MANIS_KIRI', 'MANIS_KANAN',
        'KELINGKING_KIRI', 'KELINGKING_KANAN',
    ];

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(IdentitasSearchQuery $query, CurrentUser $user): array
    {
        $builder = $this->baseBuilder($query, $user);
        $builder->groupBy('identitas.NOMOR_INDUK');

        $countSql = $builder->getCompiledSelect(false);
        $total    = (int) db_connect()->query('SELECT COUNT(*) AS c FROM (' . $countSql . ') _cnt')->getRow()->c;

        $sortCol = IdentitasSearchQuery::SORTABLE[$query->sort];
        $builder->orderBy($sortCol, $query->order);

        if (! $query->exportAll) {
            $builder->limit($query->perPage, ($query->page - 1) * $query->perPage);
        }

        $rows = $builder->get()->getResultArray();

        return [
            'rows'  => $rows,
            'total' => $total,
        ];
    }

    private function baseBuilder(IdentitasSearchQuery $query, CurrentUser $user): BaseBuilder
    {
        $builder = db_connect()->table('identitas');
        $builder->select(
            'identitas.NOMOR_INDUK AS noin,
             identitas.NOMOR_INDUK,
             identitas.NAMA_LENGKAP,
             identitas.TANGGAL_LAHIR,
             identitas.ALAMAT AS ALAMAT_WBP,
             identitas.ALAMAT,
             identitas.KONSOLIDASI AS konsol,
             identitas.ID_JENIS_KELAMIN,
             identitas.ID_JENIS_WARGANEGARA,
             identitas.FOTO_DEPAN,
             dr1.DESKRIPSI AS agama,
             dr2.DESKRIPSI AS kota,
             dr3.DESKRIPSI AS pendidikan,
             perkara.NMR_REG_GOL,
             perkara.TGL_MSK_LAPAS,
             perkara.ID_PERKARA,
             jr.IS_TAHANAN AS tahanan'
        );

        if ($user->isHq()) {
            $builder->select('upt.URAIAN');
        }

        $builder->join('daftar_referensi as dr1', 'identitas.ID_JENIS_AGAMA = dr1.ID_LOOKUP', 'left');
        $builder->join('dati2 as dr2', 'identitas.ID_KOTA = dr2.ID_DATI2', 'left');
        $builder->join('daftar_referensi as dr3', 'identitas.ID_JENIS_PENDIDIKAN = dr3.ID_LOOKUP', 'left');
        $builder->join('perkara', 'identitas.NOMOR_INDUK = perkara.NOMOR_INDUK AND perkara.IS_DELETE = 0', 'left');
        $builder->join('jenis_registrasi jr', 'jr.ID_REG = perkara.ID_REG', 'left');

        if ($user->isHq()) {
            $builder->join('upt', 'perkara.ID_UPT = upt.ID_UPT', 'left');
        }

        if ($query->fingerprintStatus !== 'semua') {
            $builder->join('sidik_jari as sj', 'identitas.NOMOR_INDUK = sj.NOMOR_INDUK', 'left');
        }

        if ($query->field === 'uu') {
            $builder->join(
                'kejahatan',
                'perkara.ID_PERKARA = kejahatan.ID_PERKARA AND perkara.NMR_REG_GOL = kejahatan.NOREGGOL AND kejahatan.IS_DELETED = 0',
                'left'
            );
        }

        $builder->where('(identitas.IS_DELETED = 0 OR identitas.IS_DELETED IS NULL)', null, false);

        if ($query->recidivistOnly) {
            $builder->where('identitas.RESIDIVIS', 'RDV1');
        }

        if ($query->activeOnly) {
            $builder->where('perkara.ID_STATUS', 'STA');
            $builder->where('(perkara.IS_DELETE = 0 OR perkara.IS_DELETE IS NULL)', null, false);
        }

        if ($query->photoStatus === 'sudah') {
            $builder->like('identitas.FOTO_DEPAN', 'upload');
        } elseif ($query->photoStatus === 'belum') {
            $builder->where("(identitas.FOTO_DEPAN NOT LIKE '%upload%' OR identitas.FOTO_DEPAN IS NULL)", null, false);
        }

        if ($query->fingerprintStatus !== 'semua') {
            $concat = "LENGTH(CONCAT_WS(''," . implode(',', array_map(
                static fn (string $col) => 'sj.' . $col,
                self::FINGERPRINT_COLS
            )) . '))';

            if ($query->fingerprintStatus === 'sudah') {
                $builder->where($concat . ' > 0', null, false);
            } else {
                $builder->where($concat . ' = 0', null, false);
            }
        }

        $this->applyKeyword($builder, $query);

        return $builder;
    }

    private function applyKeyword(BaseBuilder $builder, IdentitasSearchQuery $query): void
    {
        $q = $query->q;
        if ($q === '') {
            return;
        }

        switch ($query->field) {
            case 'nama':
                $builder->groupStart()
                    ->like('identitas.NAMA_LENGKAP', $q)
                    ->orLike('identitas.NAMA_ALIAS1', $q)
                    ->orLike('identitas.NAMA_ALIAS2', $q)
                    ->orLike('identitas.NAMA_ALIAS3', $q)
                    ->groupEnd();
                break;

            case 'uu':
                $builder->like('kejahatan.UU_KEJAHATAN', $q);
                break;

            case 'no_induk':
                $builder->like('identitas.NOMOR_INDUK', $q);
                break;

            case 'tgl':
                $ymd = DateFormat::fromDisplay($q);
                if ($ymd !== null) {
                    $builder->where('identitas.TANGGAL_LAHIR', $ymd);
                }
                break;

            case 'tgl_msk_lapas':
                $ymd = DateFormat::fromDisplay($q);
                if ($ymd !== null) {
                    $builder->where('perkara.TGL_MSK_LAPAS', $ymd);
                }
                break;

            case 'usia':
                $builder->where('getUmur(identitas.TANGGAL_LAHIR) = ' . db_connect()->escape($q), null, false);
                break;

            case 'alamat':
                $builder->like('identitas.ALAMAT', $q);
                break;

            case 'jk':
                $builder->where('identitas.ID_JENIS_KELAMIN', $q);
                break;

            case 'wg':
                $builder->where('identitas.ID_JENIS_WARGANEGARA', $q);
                break;

            case 'agama':
                $builder->where('identitas.ID_JENIS_AGAMA', $q);
                break;

            case 'pendidikan':
                $builder->like('identitas.ID_JENIS_PENDIDIKAN', $q);
                break;

            case 'ver':
                $verified = in_array($q, ['Sudah', '1', 'sudah'], true);
                $builder->where('identitas.KONSOLIDASI', $verified ? 1 : 0);
                break;

            case 'res':
                $yes = in_array($q, ['Ya', '1', 'ya'], true);
                $builder->where('identitas.IS_WBP_BERESIKO_TINGGI', $yes ? 1 : 0);
                break;

            case 'ruh':
                $yes = in_array($q, ['Ya', '1', 'ya'], true);
                $builder->where('identitas.IS_PENGARUH_TERHADAP_MASYARAKAT', $yes ? 1 : 0);
                break;

            case 'no_reg':
                $builder->like('perkara.NMR_REG_GOL', $q);
                break;
        }
    }
}
