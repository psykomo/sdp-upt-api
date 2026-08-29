<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Support\DateFormat;
use App\Modules\Shared\Support\DeskripsiLookup;
use App\Modules\Shared\Support\PublicOrigins;
use DateTimeImmutable;

final class IdentitasDetailService
{
    public function __construct(
        private readonly IdentitasRepository $identitas = new IdentitasRepository(),
        private readonly DeskripsiLookup $lookup = new DeskripsiLookup(),
        private readonly IdentitasCaseViewService $caseView = new IdentitasCaseViewService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly IdentitasAccessService $access = new IdentitasAccessService(),
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(string $nomorInduk, CurrentUser $user): ?array
    {
        $this->access->assertNomorIndukAccessible($nomorInduk, $user);

        $row = $this->identitas->findWithKtp($nomorInduk);

        if (! $row) {
            return null;
        }

        $legacy   = PublicOrigins::legacyBase();
        $frontend = PublicOrigins::frontendBase();
        $wni      = (string) ($row['ID_JENIS_WARGANEGARA'] ?? '') === 'WNI';

        return [
            'nomorInduk'  => (string) $row['NOMOR_INDUK'],
            'namaLengkap' => $row['NAMA_LENGKAP'] ?? null,
            'isTahanan'   => $this->identitas->isTahanan($nomorInduk),
            'access'      => $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS),
            'case'        => $this->caseView->forNomorInduk($nomorInduk),
            'links'       => [
                'ubah'           => $frontend . '/identitas/' . rawurlencode($nomorInduk) . '/ubah',
                'hapus'          => $frontend . '/identitas/' . rawurlencode($nomorInduk) . '/hapus',
                'cetakIdentitas' => $legacy . '/LaporanIdentitas/cetak_identitas_napi/' . rawurlencode($nomorInduk),
                'cetakTahanan'   => $legacy . '/LaporanIdentitas/cetak_identitas_tahanan/' . rawurlencode($nomorInduk),
                'cetakSidikJari' => $legacy . '/DaftarSidikJari/cetak_sidikjari/' . rawurlencode($nomorInduk),
                'kembali'        => $frontend . '/identitas',
            ],
            'biodata'     => $this->biodata($row, $wni),
            'alamat'      => $this->alamat($row, $wni),
            'pekerjaan'   => $this->pekerjaan($row),
            'keluarga'    => $this->keluarga($row),
            'fisik'       => $this->fisik($row, $legacy),
            'sidikJari'   => $this->sidikJari($nomorInduk, $legacy),
            'foto'        => $this->foto($row, $legacy),
            'history'     => $this->history($nomorInduk),
            'identitasLama' => $this->similar($nomorInduk, $frontend),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function biodata(array $row, bool $wni): array
    {
        $suku = $this->lookup->suku($row['ID_JENIS_SUKU'] ?? null);
        if (($row['ID_JENIS_SUKU'] ?? '') === 'LAN') {
            $suku = (string) ($row['ID_JENIS_SUKU_LAIN'] ?? $suku);
        }

        $agama = $this->lookup->referensi($row['ID_JENIS_AGAMA'] ?? null);
        if (($row['ID_JENIS_AGAMA'] ?? '') === 'LAIN') {
            $agama = (string) ($row['ID_JENIS_AGAMA_LAIN'] ?? $agama);
        }

        $tempatLahir = $wni
            ? $this->lookup->dati2($row['ID_TEMPAT_LAHIR'] ?? null)
            : (string) ($row['ID_TEMPAT_LAHIR_LAIN'] ?? '');
        if ($tempatLahir === '9999' || strcasecmp($tempatLahir, 'Lain-lain') === 0) {
            $tempatLahir = (string) ($row['ID_TEMPAT_LAHIR_LAIN'] ?? '');
        }

        $tempatAsal = $wni
            ? $this->dati2OrLain($row['ID_TEMPAT_ASAL'] ?? null, $row['ID_TEMPAT_ASAL_LAIN'] ?? null)
            : (string) ($row['ID_TEMPAT_ASAL_LAIN'] ?? '');

        $residivis = $this->lookup->referensi($row['RESIDIVIS'] ?? null);
        $counter   = ((string) ($row['RESIDIVIS'] ?? '') === 'RDV1')
            ? (string) ($row['RESIDIVIS_COUNTER'] ?? '')
            : '';

        return [
            'nomorInduk'                 => (string) $row['NOMOR_INDUK'],
            'nik'                        => $row['NIK'] ?? null,
            'residivis'                  => $residivis,
            'residivisKe'                => $counter,
            'namaLengkap'                => $row['NAMA_LENGKAP'] ?? null,
            'namaAlias1'                 => $row['NAMA_ALIAS1'] ?? null,
            'namaAlias2'                 => $row['NAMA_ALIAS2'] ?? null,
            'namaAlias3'                 => $row['NAMA_ALIAS3'] ?? null,
            'namaKecil1'                 => $row['NAMA_KECIL1'] ?? null,
            'namaKecil2'                 => $row['NAMA_KECIL2'] ?? null,
            'namaKecil3'                 => $row['NAMA_KECIL3'] ?? null,
            'tempatAsal'                 => $tempatAsal,
            'tempatLahir'                => $tempatLahir,
            'tanggalLahir'               => DateFormat::toDisplay($row['TANGGAL_LAHIR'] ?? null, '/'),
            'usia'                       => $this->usia($row['TANGGAL_LAHIR'] ?? null),
            'jenisKelamin'               => $this->lookup->referensi($row['ID_JENIS_KELAMIN'] ?? null),
            'negara'                     => $this->lookup->negara($row['ID_NEGARA_ASING'] ?? null),
            'kewarganegaraan'            => $this->lookup->referensi($row['ID_JENIS_WARGANEGARA'] ?? null),
            'agama'                      => $agama,
            'suku'                       => $suku,
            'statusPerkawinan'           => $this->lookup->referensi($row['ID_JENIS_STATUS_PERKAWINAN'] ?? null),
            'beresikoTinggi'             => (int) ($row['IS_WBP_BERESIKO_TINGGI'] ?? 0) === 1,
            'pengaruhMasyarakat'         => (int) ($row['IS_PENGARUH_TERHADAP_MASYARAKAT'] ?? 0) === 1,
            'disabilitas'                => (int) ($row['IS_DISABILITAS'] ?? 0) === 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function alamat(array $row, bool $wni): array
    {
        return [
            'propinsi'         => $wni ? $this->lookup->propinsi($row['ID_PROPINSI'] ?? null) : (string) ($row['ID_PROPINSI_LAIN'] ?? ''),
            'kota'             => $wni ? $this->lookup->dati2($row['ID_KOTA'] ?? null) : (string) ($row['ID_KOTA_LAIN'] ?? ''),
            'alamat'           => $row['ALAMAT'] ?? null,
            'alamatAlternatif' => $row['ALAMAT_ALTERNATIF'] ?? null,
            'telepon'          => $row['TELEPON'] ?? null,
            'kodepos'          => $row['KODEPOS'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function pekerjaan(array $row): array
    {
        $kerja = $this->lookup->pekerjaan($row['ID_JENIS_PEKERJAAN'] ?? null);
        if ((string) ($row['ID_JENIS_PEKERJAAN'] ?? '') === '89') {
            $kerja = (string) ($row['ID_JENIS_PEKERJAAN_LAIN'] ?? $kerja);
        }

        $pendidikan = $this->lookup->referensi($row['ID_JENIS_PENDIDIKAN'] ?? null);
        if ((string) ($row['ID_JENIS_PENDIDIKAN'] ?? '') === 'PDLA') {
            $pendidikan = (string) ($row['ID_JENIS_PENDIDIKAN_LAIN'] ?? $pendidikan);
        }

        $keahlian1 = $this->lookup->referensi($row['ID_JENIS_KEAHLIAN_1'] ?? null);
        if ((string) ($row['ID_JENIS_KEAHLIAN_1'] ?? '') === 'ZA99') {
            $keahlian1 = (string) ($row['ID_JENIS_KEAHLIAN_1_LAIN'] ?? $keahlian1);
        }

        $keahlian2 = $this->lookup->referensi($row['ID_JENIS_KEAHLIAN_2'] ?? null);
        if ((string) ($row['ID_JENIS_KEAHLIAN_2'] ?? '') === 'ZA99') {
            $keahlian2 = (string) ($row['ID_JENIS_KEAHLIAN_2_LAIN'] ?? $keahlian2);
        }

        $penghasilan = $this->lookup->referensi($row['ID_TINGKAT_PENGHASILAN'] ?? null);

        return [
            'jenisPekerjaan'      => $kerja,
            'namaInstansiPns'     => $row['NAMA_INSTANSI_PNS'] ?? null,
            'nip'                 => $row['NIP'] ?? null,
            'alamatPekerjaan'     => $row['ALAMAT_PEKERJAAN'] ?? null,
            'keteranganPekerjaan' => $row['KETERANGAN_PEKERJAAN'] ?? null,
            'tingkatPenghasilan'  => $penghasilan !== '' ? $penghasilan : 'Belum diisi',
            'pendidikan'          => $pendidikan,
            'keahlian1'           => $keahlian1,
            'level1'              => $this->lookup->referensi($row['ID_JENIS_LEVEL_1'] ?? null),
            'keahlian2'           => $keahlian2,
            'level2'              => $this->lookup->referensi($row['ID_JENIS_LEVEL_2'] ?? null),
            'minat'               => $row['MINAT'] ?? null,
            'bacaLatin'           => ((int) ($row['IS_BACA_LATIN'] ?? 0) === 1) ? 'Bisa' : 'Tidak Bisa',
            'bacaQuran'           => ((int) ($row['IS_BACA_QURAN'] ?? 0) === 1) ? 'Bisa' : 'Tidak Bisa',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function keluarga(array $row): array
    {
        return [
            'namaAyah'           => $row['NM_AYAH'] ?? null,
            'alamatAyah'         => $row['TMP_TGL_AYAH'] ?? null,
            'namaIbu'            => $row['NM_IBU'] ?? null,
            'alamatIbu'          => $row['TMP_TGL_IBU'] ?? null,
            'anakKe'             => $row['ANAKKE'] ?? null,
            'jumlahSaudara'      => $row['JML_SAUDARA'] ?? null,
            'namaSaudara'        => $this->lines($row['NM_SAUDARA'] ?? null),
            'jumlahIstriSuami'   => $row['JML_ISTRI_SUAMI'] ?? null,
            'namaIstriSuami'     => $this->lines($row['NM_ISTRI_SUAMI'] ?? null),
            'alamatIstriSuami'   => $row['TMP_TGL_ISTRI_SUAMI'] ?? null,
            'jumlahAnak'         => $row['JML_ANAK'] ?? null,
            'namaAnak'           => $this->lines($row['NM_ANAK'] ?? null),
            'teleponKeluarga'    => $row['TELEPHONE_KELUARGA'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function fisik(array $row, string $legacy): array
    {
        return [
            'tinggi'        => $row['TINGGI'] ?? null,
            'berat'         => $row['BERAT'] ?? null,
            'bentukRambut'  => $this->lookup->referensi($row['ID_BENTUKRAMBUT'] ?? null),
            'warnaRambut'   => $this->lookup->referensi($row['ID_JENIS_RAMBUT'] ?? null),
            'bentukBibir'   => $this->lookup->referensi($row['ID_BENTUKBIBIR'] ?? null),
            'kacamata'      => $this->lookup->referensi($row['ID_KACAMATA'] ?? null),
            'bentukMata'    => $this->lookup->referensi($row['ID_BENTUK_MATA'] ?? null),
            'warnaMata'     => $this->lookup->referensi($row['ID_WARNA_MATA'] ?? null),
            'hidung'        => $this->lookup->referensi($row['ID_JENIS_HIDUNG'] ?? null),
            'rautMuka'      => $this->lookup->referensi($row['ID_JENIS_MUKA'] ?? null),
            'telinga'       => $this->lookup->referensi($row['ID_TELINGA'] ?? null),
            'mulut'         => $this->lookup->referensi($row['ID_JENIS_MULUT'] ?? null),
            'lengan'        => $this->lookup->referensi($row['ID_LENGAN'] ?? null),
            'tangan'        => $this->lookup->referensi($row['ID_JENIS_TANGAN'] ?? null),
            'kaki'          => $this->lookup->referensi($row['ID_JENIS_KAKI'] ?? null),
            'warnaKulit'    => $this->lookup->referensi($row['ID_WARNAKULIT'] ?? null),
            'cacat'         => $row['CACAT'] ?? null,
            'ciri'          => [
                ['fotoUrl' => $this->mediaUrl($legacy, $row['FOTO_CIRI_1'] ?? null), 'keterangan' => $row['CIRI'] ?? null],
                ['fotoUrl' => $this->mediaUrl($legacy, $row['FOTO_CIRI_2'] ?? null), 'keterangan' => $row['CIRI2'] ?? null],
                ['fotoUrl' => $this->mediaUrl($legacy, $row['FOTO_CIRI_3'] ?? null), 'keterangan' => $row['CIRI3'] ?? null],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sidikJari(string $nomorInduk, string $legacy): array
    {
        $row = $this->identitas->findSidikJari($nomorInduk, true);

        $empty = [
            'jempolKanan' => null, 'telunjukKanan' => null, 'tengahKanan' => null, 'manisKanan' => null, 'kelingkingKanan' => null,
            'jempolKiri' => null, 'telunjukKiri' => null, 'tengahKiri' => null, 'manisKiri' => null, 'kelingkingKiri' => null,
            'noPaspor' => null, 'pengambil' => null, 'rumusDaktil' => null, 'nomorDaktil' => null, 'tanggalAmbil' => null,
        ];

        if (! $row) {
            return $empty;
        }

        return [
            'jempolKanan'     => $this->mediaUrl($legacy, $row['JEMPOL_KANAN'] ?? null),
            'telunjukKanan'   => $this->mediaUrl($legacy, $row['TELUNJUK_KANAN'] ?? null),
            'tengahKanan'     => $this->mediaUrl($legacy, $row['TENGAH_KANAN'] ?? null),
            'manisKanan'      => $this->mediaUrl($legacy, $row['MANIS_KANAN'] ?? null),
            'kelingkingKanan' => $this->mediaUrl($legacy, $row['KELINGKING_KANAN'] ?? null),
            'jempolKiri'      => $this->mediaUrl($legacy, $row['JEMPOL_KIRI'] ?? null),
            'telunjukKiri'    => $this->mediaUrl($legacy, $row['TELUNJUK_KIRI'] ?? null),
            'tengahKiri'      => $this->mediaUrl($legacy, $row['TENGAH_KIRI'] ?? null),
            'manisKiri'       => $this->mediaUrl($legacy, $row['MANIS_KIRI'] ?? null),
            'kelingkingKiri'  => $this->mediaUrl($legacy, $row['KELINGKING_KIRI'] ?? null),
            'noPaspor'        => $row['NO_PASPOR'] ?? null,
            'pengambil'       => $row['PENGAMBIL_SJ'] ?? null,
            'rumusDaktil'     => $row['RUMUS_DAKTIL'] ?? null,
            'nomorDaktil'     => $row['NOMOR_DAKTIL'] ?? null,
            'tanggalAmbil'    => DateFormat::toDisplay($row['TANGGAL_SJ'] ?? null, '/'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function foto(array $row, string $legacy): array
    {
        return [
            'kiri'    => $this->mediaUrl($legacy, $row['FOTO_KIRI'] ?? null),
            'depan'   => $this->mediaUrl($legacy, $row['FOTO_DEPAN'] ?? null),
            'kanan'   => $this->mediaUrl($legacy, $row['FOTO_KANAN'] ?? null),
            'closeup' => $this->mediaUrl($legacy, $row['FOTO_CLOSEUP'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(string $nomorInduk): array
    {
        $rows = $this->identitas->attributeHistory($nomorInduk);

        $items = [];
        foreach ($rows as $row) {
            $created = (string) ($row['CREATED'] ?? '');
            $items[] = [
                'namaField'       => $row['NAMA_FIELD'] ?? null,
                'isi'             => $row['ISI_FIELD'] ?? null,
                'nomorBerkas'     => $row['ID_PERKARA'] ?? null,
                'jenisRegistrasi' => $row['JENIS_REGISTRASI'] ?? ($row['ID_REG'] ?? null),
                'diubah'          => $created !== '' ? date('d/m/Y H:i:s', strtotime($created)) : null,
                'yangMengubah'    => $row['CREATED_BY'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function similar(string $nomorInduk, string $frontend): array
    {
        $rows = $this->identitas->similarRows($nomorInduk);

        foreach ($rows as &$row) {
            $id = (string) ($row['nomorInduk'] ?? '');
            $row['href'] = $id !== '' ? $frontend . '/identitas/' . rawurlencode($id) : null;
        }
        unset($row);

        return $rows;
    }

    private function dati2OrLain(?string $id, ?string $lain): string
    {
        $label = $this->lookup->dati2($id);
        if ($lain !== null && trim($lain) !== '') {
            return trim($lain);
        }

        return $label;
    }

    /**
     * @return list<string>
     */
    private function lines(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = preg_split("/\r\n|\n|\r/", $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($line) => $line !== ''));
    }

    private function usia(?string $ymd): ?string
    {
        if ($ymd === null || trim($ymd) === '' || $ymd === '0000-00-00') {
            return null;
        }

        try {
            $born = new DateTimeImmutable(substr($ymd, 0, 10));
            $now  = new DateTimeImmutable('today');
        } catch (\Exception) {
            return null;
        }

        $years = (int) $now->format('Y') - (int) $born->format('Y');
        if ((int) $now->format('md') < (int) $born->format('md')) {
            $years--;
        }

        return $years . ' tahun';
    }

    private function mediaUrl(string $legacy, ?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = ltrim($path, '/');

        return $legacy . '/' . $path;
    }
}
