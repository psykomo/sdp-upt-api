<?php

namespace App\Modules\Identitas\Repositories;

final class IdentitasRepository implements IdentitasRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(string $nomorInduk): ?array
    {
        return db_connect()->table('identitas')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->get()
            ->getRowArray() ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithKtp(string $nomorInduk): ?array
    {
        return db_connect()->table('identitas')
            ->select('identitas.*, identitas_ktp.NAMA_LENGKAP AS NAMA_LENGKAP_KTP, identitas_ktp.TEMPAT_LAHIR AS TEMPAT_LAHIR_KTP')
            ->join('identitas_ktp', 'identitas_ktp.NIK = identitas.NIK', 'left')
            ->where('identitas.NOMOR_INDUK', $nomorInduk)
            ->get()
            ->getRowArray() ?: null;
    }

    /**
     * Active STA parent perkara UPT context for row scoping (legacy ManajemenIdentitas::viewEdit).
     *
     * @return array{idUpt: string, kanwil: string}|null
     */
    public function activeStaParentPerkaraContext(string $nomorInduk): ?array
    {
        return (new \App\Modules\Shared\Access\PerkaraScopeRepository())->activeStaParentPerkaraContext($nomorInduk);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function update(string $nomorInduk, array $row): void
    {
        db_connect()->table('identitas')->where('NOMOR_INDUK', $nomorInduk)->update($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): void
    {
        db_connect()->table('identitas')->insert($row);
    }

    /**
     * Legacy func::createNomorInduk — prefix is UPT id + Ymd + 4-digit sequence.
     */
    public function nextNomorInduk(string $uptId): string
    {
        $uptId  = trim($uptId);
        $prefix = $uptId . date('Ymd');
        $row    = db_connect()->table('identitas')
            ->selectMax('NOMOR_INDUK', 'maxId')
            ->like('NOMOR_INDUK', $prefix, 'after')
            ->get()
            ->getRowArray();

        $maxId = (string) ($row['maxId'] ?? '');
        $seq   = 1;
        if ($maxId !== '' && str_starts_with($maxId, $prefix)) {
            $tail = substr($maxId, strlen($prefix));
            if (ctype_digit($tail)) {
                $seq = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Legacy AddIdentitas::validasiLokal — NM_IBU is matched to the second argument
     * (caller passes NM_AYAH). Port the quirk; do not "fix" to namaIbu.
     *
     * @return list<array<string, mixed>>
     */
    public function findCreateDuplicates(string $namaLengkap, string $nmIbuComparedToAyah, string $tanggalLahirYmd, ?string $tempatLahirId): array
    {
        $builder = db_connect()->table('identitas')
            ->select('identitas.NOMOR_INDUK, identitas.NAMA_LENGKAP, identitas.TANGGAL_LAHIR, identitas.NM_IBU, identitas.NM_AYAH, identitas.ID_JENIS_KELAMIN, identitas.KONSOLIDASI, dati2.DESKRIPSI AS tempatLahir')
            ->join('dati2', 'identitas.ID_TEMPAT_LAHIR = dati2.ID_DATI2', 'left')
            ->like('identitas.NAMA_LENGKAP', $namaLengkap)
            ->where('identitas.IS_DELETED', '0')
            ->where('identitas.TANGGAL_LAHIR', $tanggalLahirYmd)
            ->where('identitas.NM_IBU', $nmIbuComparedToAyah);

        if ($tempatLahirId !== null && $tempatLahirId !== '') {
            $builder->where('identitas.ID_TEMPAT_LAHIR', $tempatLahirId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSidikJari(string $nomorInduk, bool $requireTanggal = false): ?array
    {
        $builder = db_connect()->table('sidik_jari')->where('NOMOR_INDUK', $nomorInduk);
        if ($requireTanggal) {
            $builder->where('TANGGAL_SJ IS NOT NULL', null, false);
        }

        return $builder->get()->getRowArray() ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function upsertSidikJari(string $nomorInduk, array $row): void
    {
        $exists = $this->findSidikJari($nomorInduk);
        if ($exists) {
            db_connect()->table('sidik_jari')->where('NOMOR_INDUK', $nomorInduk)->update($row);

            return;
        }

        $row['NOMOR_INDUK'] = $nomorInduk;
        if (empty($row['ID_SIDIK_JARI'])) {
            unset($row['ID_SIDIK_JARI']);
        }
        db_connect()->table('sidik_jari')->insert($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function upsertUsertbl(string $nomorInduk, array $row): void
    {
        $exists = db_connect()->table('usertbl')
            ->where('dbid', $nomorInduk)
            ->get()
            ->getRowArray();

        $row['dbid'] = $nomorInduk;
        if ($exists) {
            db_connect()->table('usertbl')->where('dbid', $nomorInduk)->update($row);

            return;
        }

        db_connect()->table('usertbl')->insert($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function similarRows(string $nomorInduk): array
    {
        return db_connect()->table('identitas_mirip im')
            ->select('im.NOMOR_INDUK_2 AS nomorInduk, identitas.NAMA_LENGKAP AS namaLengkap')
            ->join('identitas', 'identitas.NOMOR_INDUK = im.NOMOR_INDUK_2', 'left')
            ->where('im.NOMOR_INDUK_1', $nomorInduk)
            ->where('im.IS_DELETE', '0')
            ->get()
            ->getResultArray();
    }

    public function similarIdsCsv(string $nomorInduk): string
    {
        $row = db_connect()->table('identitas_mirip')
            ->select('GROUP_CONCAT(NOMOR_INDUK_2) AS ids')
            ->where('NOMOR_INDUK_1', $nomorInduk)
            ->where('IS_DELETE', '0')
            ->get()
            ->getRowArray();

        return rtrim((string) ($row['ids'] ?? ''), ',');
    }

    /**
     * @param list<string> $nomorIndukLain
     */
    public function replaceKemiripan(string $nomorInduk, array $nomorIndukLain, ?string $uptId): void {
        db_connect()->table('identitas_mirip')
            ->where('NOMOR_INDUK_1', $nomorInduk)
            ->update(['IS_DELETE' => 1]);

        if ($nomorIndukLain === []) {
            return;
        }

        db_connect()->table('identitas')->where('NOMOR_INDUK', $nomorInduk)->update(['RESIDIVIS' => 'RDV1']);

        $copiedSidikJari = false;
        foreach ($nomorIndukLain as $other) {
            $row = db_connect()->table('identitas_mirip')
                ->where('NOMOR_INDUK_1', $nomorInduk)
                ->where('NOMOR_INDUK_2', $other)
                ->get()
                ->getRowArray();

            if ($row) {
                db_connect()->table('identitas_mirip')
                    ->where('NOMOR_INDUK_1', $nomorInduk)
                    ->where('NOMOR_INDUK_2', $other)
                    ->update(['IS_DELETE' => 0]);
            } else {
                db_connect()->table('identitas_mirip')->insert([
                    'ID_IDENTITAS_MIRIP' => $this->nextIdentitasMiripId($uptId),
                    'NOMOR_INDUK_1'      => $nomorInduk,
                    'NOMOR_INDUK_2'      => $other,
                    'IS_DELETE'          => 0,
                ]);
            }

            if (! $copiedSidikJari) {
                $copiedSidikJari = $this->copySidikJariFromSimilar($nomorInduk, $other);
            }
        }
    }

    public static function formatIdentitasMiripId(?string $uptId, ?string $maxId, int $places = 4): string
    {
        $uptId = trim((string) $uptId);
        if ($uptId === '') {
            return bin2hex(random_bytes(16));
        }

        $prefix = $uptId . date('Ymd');
        $seq    = 1;
        if ($maxId !== null && $maxId !== '' && str_starts_with((string) $maxId, $prefix)) {
            $tail = substr((string) $maxId, strlen($prefix));
            if (ctype_digit($tail)) {
                $seq = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $seq, $places, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attributeHistory(string $nomorInduk): array
    {
        return db_connect()->table('identitas_attribute_histori ias')
            ->select('ias.NAMA_FIELD, ias.ISI_FIELD, ias.ID_PERKARA, ias.ID_REG, ias.CREATED, ias.CREATED_BY, jr.DESKRIPSI AS JENIS_REGISTRASI')
            ->join('jenis_registrasi jr', 'ias.ID_REG = jr.ID_REG', 'left')
            ->where('ias.NOMOR_INDUK', $nomorInduk)
            ->orderBy('ias.UPDATED', 'DESC')
            ->orderBy('ias.CREATED', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertChangeLog(array $row): void
    {
        db_connect()->table('log_file')->insert($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function caseViewRow(string $nomorInduk): ?array
    {
        return db_connect()->table('perkara')
            ->select('perkara.ID_PERKARA, perkara.LOKASI_SEL, perkara.NMR_REG_GOL, jr.DESKRIPSI AS jenis, perkara.ID_REG, perkara.ID_STATUS, perkara.ID_SUB_STATUS, perkara.TGL_PERTAMA_DITAHAN, perkara.TGL_EKSPIRASI, perkara.TGL_MSK_LAPAS, jr.IS_TAHANAN, sel.NO_SEL')
            ->join('jenis_registrasi jr', 'jr.ID_REG = perkara.ID_REG', 'left')
            ->join('sel', 'perkara.LOKASI_SEL = sel.ID_SEL', 'left')
            ->where('perkara.NOMOR_INDUK', $nomorInduk)
            ->where('perkara.IS_DELETE', 0)
            ->get()
            ->getRowArray() ?: null;
    }

    public function kejahatanUtamaLabel(string $idPerkara, string $nmrRegGol): string
    {
        $row = db_connect()->table('kejahatan k')
            ->select('t.TERMINOLOGI')
            ->join('terminologi t', 'k.ID_TERMINOLOGI = t.ID_TERMINOLOGI')
            ->where('k.ID_PERKARA', $idPerkara)
            ->like('k.NOREGGOL', $nmrRegGol)
            ->where('k.IS_KEJAHATAN_UTAMA', '1')
            ->where('k.IS_DELETED', '0')
            ->get()
            ->getRowArray();

        return (string) ($row['TERMINOLOGI'] ?? 'kosong');
    }

    public function isTahanan(string $nomorInduk): bool
    {
        $row = db_connect()->table('perkara')
            ->select('jr.IS_TAHANAN')
            ->join('jenis_registrasi jr', 'jr.ID_REG = perkara.ID_REG', 'left')
            ->where('perkara.NOMOR_INDUK', $nomorInduk)
            ->where('perkara.IS_DELETE', 0)
            ->get()
            ->getRowArray();

        return (int) ($row['IS_TAHANAN'] ?? 0) === 1;
    }

    public function hasOpenPerkara(string $nomorInduk): bool
    {
        $row = db_connect()->table('perkara')
            ->select('ID_PERKARA')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->where('IS_DELETE', 0)
            ->get()
            ->getRowArray();

        return $row !== null;
    }

    /**
     * Mutasi UPT rows for national photo path fallback (Lib_identitas::check_foto_mutasi_upt).
     *
     * @return list<array<string, mixed>>
     */
    public function mutasiPhotoRows(string $nomorInduk): array
    {
        return db_connect()->table('perkara p')
            ->select('p.ID_UPT, muh.ID_UPT_ASAL, i.FOTO_DEPAN, i.FOTO_KANAN, i.FOTO_KIRI, i.FOTO_CIRI_1, i.FOTO_CIRI_2, i.FOTO_CIRI_3')
            ->join('identitas i', 'i.NOMOR_INDUK = p.NOMOR_INDUK', 'left')
            ->join('mutasi_upt_detail mud', 'mud.ID_PERKARA = p.ID_PERKARA AND mud.DITOLAK = 0', 'left')
            ->join('mutasi_upt_header muh', 'muh.ID_MUTASI_TAHANAN = mud.ID_MUTASI_UPT_HEADER', 'left')
            ->where('i.NOMOR_INDUK', $nomorInduk)
            ->where('p.IS_DELETE', 0)
            ->where('i.IS_DELETED', 0)
            ->orderBy('muh.TGL_ENTRY', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * STA parent perkara id for AKSES002 gate (ManajemenIdentitas::viewEdit).
     */
    public function activeStaParentPerkaraId(string $nomorInduk): ?string
    {
        $row = db_connect()->table('identitas')
            ->select('perkara.ID_PERKARA')
            ->join('perkara', 'perkara.NOMOR_INDUK = identitas.NOMOR_INDUK', 'left')
            ->where('identitas.NOMOR_INDUK', $nomorInduk)
            ->where('identitas.IS_DELETED', 0)
            ->where('perkara.IS_DELETE', 0)
            ->where('perkara.ID_STATUS', 'STA')
            ->where('perkara.ID_PERKARA_PARENT IS NULL', null, false)
            ->limit(1)
            ->get()
            ->getRowArray();

        $id = trim((string) ($row['ID_PERKARA'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /**
     * UPT id on STA parent perkara (fallback when JWT uptId empty on Pusat).
     */
    public function activeStaParentUptId(string $nomorInduk): ?string
    {
        $context = $this->activeStaParentPerkaraContext($nomorInduk);

        return $context !== null ? $context['idUpt'] : null;
    }

    public function hasProcessedRemisi(string $idPerkara): bool
    {
        $row = db_connect()->table('remisi')
            ->select('TGL_PERUNTUKKAN')
            ->where('ID_PERKARA', $idPerkara)
            ->groupStart()
                ->where('STATUS_RMS', 2)
                ->orWhere('STATUS_RMS', 4)
                ->orGroupStart()
                    ->where('STATUS_RMS', 1)
                    ->where('NMR_SRT_SK IS NOT NULL', null, false)
                ->groupEnd()
            ->groupEnd()
            ->where('IS_PROCESS', 1)
            ->where('IS_DELETED', 0)
            ->orderBy('TGL_PERUNTUKKAN', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row !== null && trim((string) ($row['TGL_PERUNTUKKAN'] ?? '')) !== '';
    }

    public function hasSidangTppAkses002(string $idPerkara, string $idUpt): bool
    {
        $today = date('Y-m-d');

        $row = db_connect()->table('sidang_tpp_usulan_perbaikan pr')
            ->select('pd.ID_SIDANG_TPP_USULAN_PERBAIKAN_DETAIL')
            ->join(
                'sidang_tpp_usulan_perbaikan_detail pd',
                'pr.ID_SIDANG_TPP_USULAN_PERBAIKAN = pd.ID_SIDANG_TPP_USULAN_PERBAIKAN',
            )
            ->where('pr.ID_UPT', $idUpt)
            ->where('pr.JENIS', 'akses_data')
            ->where('pr.TABLE', 'perkara')
            ->where('pr.ID_REF', $idPerkara)
            ->where('pr.TGL_AKSES_MULAI <=', $today)
            ->where('pr.TGL_AKSES_SELESAI >=', $today)
            ->where('pd.ID_LOOKUP', 'AKSES002')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row !== null;
    }

    public function softDelete(string $nomorInduk): void
    {
        db_connect()->table('identitas')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->update(['IS_DELETED' => 1]);

        db_connect()->table('sidik_jari')
            ->where('NOMOR_INDUK', $nomorInduk)
            ->update(['IS_DELETED' => 1]);

        db_connect()->table('usertbl')
            ->where('dbid', $nomorInduk)
            ->update(['IS_DELETED' => 1]);
    }

    private function nextIdentitasMiripId(?string $uptId): string
    {
        $uptId  = trim((string) $uptId);
        $prefix = $uptId === '' ? null : $uptId . date('Ymd');
        $maxId  = null;
        if ($prefix !== null) {
            $row = db_connect()->table('identitas_mirip')
                ->selectMax('ID_IDENTITAS_MIRIP', 'maxId')
                ->like('ID_IDENTITAS_MIRIP', $prefix, 'after')
                ->get()
                ->getRowArray();
            $maxId = $row['maxId'] ?? null;
        }

        return self::formatIdentitasMiripId($uptId, $maxId === '' ? null : $maxId);
    }

    private function copySidikJariFromSimilar(string $nomorInduk, string $other): bool
    {
        $source = $this->findSidikJariWithTemplate($other);
        if ($source === null) {
            return false;
        }

        $this->upsertSidikJari($nomorInduk, [
            'ID_SIDIK_JARI'    => $nomorInduk,
            'PENGAMBIL_SJ'     => $source['PENGAMBIL_SJ'] ?? null,
            'NO_PASPOR'        => $source['NO_PASPOR'] ?? null,
            'RUMUS_DAKTIL'     => $source['RUMUS_DAKTIL'] ?? null,
            'NOMOR_DAKTIL'     => $source['NOMOR_DAKTIL'] ?? null,
            'JEMPOL_KANAN'     => $source['JEMPOL_KANAN'] ?? null,
            'TELUNJUK_KANAN'   => $source['TELUNJUK_KANAN'] ?? null,
            'TENGAH_KANAN'     => $source['TENGAH_KANAN'] ?? null,
            'MANIS_KANAN'      => $source['MANIS_KANAN'] ?? null,
            'KELINGKING_KANAN' => $source['KELINGKING_KANAN'] ?? null,
            'JEMPOL_KIRI'      => $source['JEMPOL_KIRI'] ?? null,
            'TELUNJUK_KIRI'    => $source['TELUNJUK_KIRI'] ?? null,
            'TENGAH_KIRI'      => $source['TENGAH_KIRI'] ?? null,
            'MANIS_KIRI'       => $source['MANIS_KIRI'] ?? null,
            'KELINGKING_KIRI'  => $source['KELINGKING_KIRI'] ?? null,
            'TANGGAL_SJ'       => $source['TANGGAL_SJ'] ?? null,
            'KONSOLIDASI'      => 0,
        ]);

        $this->upsertUsertbl($nomorInduk, [
            'template'    => $source['template'] ?? null,
            'hash_name'   => $source['hash_name'] ?? null,
            'finger'      => $source['finger'] ?? null,
            'scan'        => $source['scan'] ?? null,
            'user'        => $source['user'] ?? null,
            'date'        => $source['date'] ?? null,
            'size'        => $source['size'] ?? null,
            'g'           => $source['g'] ?? null,
            'KONSOLIDASI' => 0,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSidikJariWithTemplate(string $nomorInduk): ?array
    {
        return db_connect()->table('sidik_jari s')
            ->select('s.*, u.template, u.hash_name, u.finger, u.scan, u.user, u.date, u.size, u.g')
            ->join('usertbl u', 'u.dbid = s.NOMOR_INDUK', 'left')
            ->where('s.NOMOR_INDUK', $nomorInduk)
            ->where(
                '(s.JEMPOL_KANAN IS NOT NULL OR s.JEMPOL_KIRI IS NOT NULL OR s.KELINGKING_KANAN IS NOT NULL OR s.KELINGKING_KIRI IS NOT NULL OR s.MANIS_KANAN IS NOT NULL OR s.MANIS_KIRI IS NOT NULL OR s.TELUNJUK_KANAN IS NOT NULL OR s.TELUNJUK_KIRI IS NOT NULL OR s.TENGAH_KANAN IS NOT NULL OR s.TENGAH_KIRI IS NOT NULL)',
                null,
                false,
            )
            ->where('u.template IS NOT NULL', null, false)
            ->get()
            ->getRowArray() ?: null;
    }
}
