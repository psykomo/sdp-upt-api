<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Dokumen\Services\DokumenService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Identitas\Support\IdentitasPhotoResolver;
use App\Modules\Identitas\Services\IdentitasUpdateException;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Support\DateFormat;
use App\Modules\Shared\Support\DeskripsiLookup;
use App\Modules\Shared\Support\PublicOrigins;

final class IdentitasFormService
{
    public function __construct(
        private readonly IdentitasRepository $identitas = new IdentitasRepository(),
        private readonly DeskripsiLookup $lookup = new DeskripsiLookup(),
        private readonly IdentitasCaseViewService $caseView = new IdentitasCaseViewService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly IdentitasAccessService $access = new IdentitasAccessService(),
        private readonly IdentitasSensitiveFieldService $sensitive = new IdentitasSensitiveFieldService(),
        private readonly IdentitasPhotoResolver $photos = new IdentitasPhotoResolver(),
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(string $nomorInduk, CurrentUser $user): ?array
    {
        $access = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        if (! $access['canWrite']) {
            throw new IdentitasUpdateException(
                'Mohon maaf anda tidak berhak mengubah data identitas.',
                [],
                403,
            );
        }

        $this->access->assertNomorIndukAccessible($nomorInduk, $user);

        $row = $this->identitas->find($nomorInduk);

        if (! $row) {
            return null;
        }

        $legacy   = PublicOrigins::legacyBase();
        $frontend = PublicOrigins::frontendBase();
        $wni      = $this->wargaNegara($row) === 'WNI';

        $locked = $this->sensitive->lockedFields($nomorInduk, $user);

        return [
            'nomorInduk'  => $nomorInduk,
            'namaLengkap' => $this->namaLengkapDisplay($row),
            'isTahanan'   => $this->identitas->isTahanan($nomorInduk),
            'access'      => $access,
            'readOnly'    => ! $access['canWrite'],
            'canEditSensitiveFields' => $locked === [],
            'lockedFields' => $locked,
            'case'        => $this->caseView->forNomorInduk($nomorInduk),
            'links'       => [
                'lihat'          => $frontend . '/identitas/' . rawurlencode($nomorInduk),
                'kembali'        => $frontend . '/identitas',
                'hapus'          => $frontend . '/identitas/' . rawurlencode($nomorInduk) . '/hapus',
                'cetakIdentitas' => $legacy . '/LaporanIdentitas/cetak_identitas_napi/' . rawurlencode($nomorInduk),
                'cetakTahanan'   => $legacy . '/LaporanIdentitas/cetak_identitas_tahanan/' . rawurlencode($nomorInduk),
                'cetakSidikJari' => $legacy . '/DaftarSidikJari/cetak_sidikjari/' . rawurlencode($nomorInduk),
                'rekamSidikJari' => $legacy . '/ManajemenIdentitas/viewEdit/' . rawurlencode($nomorInduk) . '?legacy=1',
            ],
            'values'      => $this->values($row, $nomorInduk, $wni),
            'foto'        => $this->foto($row, $legacy, $nomorInduk, $user),
            'sidikJari'   => $this->sidikJari($nomorInduk, $legacy),
            'identitasLama' => $this->similar($nomorInduk, $frontend),
            'documents'   => (new DokumenService())->listIdentitasDocuments($nomorInduk, $user),
            'lookups'     => $this->lookups(),
        ];
    }

    /**
     * Empty create form — no nomor induk assert (row does not exist yet).
     *
     * @return array<string, mixed>
     */
    public function emptyForm(CurrentUser $user): array
    {
        $access = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        if (! $access['canWrite']) {
            throw new IdentitasUpdateException(
                'Mohon maaf anda tidak berhak menambah data identitas.',
                [],
                403,
            );
        }

        $frontend = PublicOrigins::frontendBase();
        $empty    = $this->values([], '', true);
        $empty['nomorInduk'] = '';
        $empty['nomorIndukSimilar'] = '';
        $empty['kewarganegaraan'] = 'WNI';

        return [
            'nomorInduk'             => null,
            'namaLengkap'            => '',
            'isTahanan'              => false,
            'access'                 => $access,
            'readOnly'               => false,
            'canEditSensitiveFields' => true,
            'lockedFields'           => [],
            'case'                   => null,
            'links'                  => [
                'kembali' => $frontend . '/identitas',
            ],
            'values'                 => $empty,
            'foto'                   => [
                'kiri' => null, 'depan' => null, 'kanan' => null, 'closeup' => null,
                'ciri1' => null, 'ciri2' => null, 'ciri3' => null,
            ],
            'sidikJari'              => [
                'jempolKanan' => null, 'telunjukKanan' => null, 'tengahKanan' => null,
                'manisKanan' => null, 'kelingkingKanan' => null,
                'jempolKiri' => null, 'telunjukKiri' => null, 'tengahKiri' => null,
                'manisKiri' => null, 'kelingkingKiri' => null,
            ],
            'identitasLama'          => [],
            'documents'              => [],
            'lookups'                => $this->lookups(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function values(array $row, string $nomorInduk, bool $wni): array
    {
        $sidik = $this->identitas->findSidikJari($nomorInduk) ?? [];
        $similarIds = $this->identitas->similarIdsCsv($nomorInduk);

        return [
            'nomorInduk'               => $nomorInduk,
            'nik'                      => $this->str($row['NIK'] ?? null),
            'residivis'                => $this->str($row['RESIDIVIS'] ?? null),
            'residivisKe'              => $this->str($row['RESIDIVIS_COUNTER'] ?? null),
            'namaLengkap'              => $this->namaLengkapDisplay($row),
            'namaAlias1'               => $this->str($row['NAMA_ALIAS1'] ?? null),
            'namaAlias2'               => $this->str($row['NAMA_ALIAS2'] ?? null),
            'namaAlias3'               => $this->str($row['NAMA_ALIAS3'] ?? null),
            'namaKecil1'               => $this->str($row['NAMA_KECIL1'] ?? null),
            'namaKecil2'               => $this->str($row['NAMA_KECIL2'] ?? null),
            'namaKecil3'               => $this->str($row['NAMA_KECIL3'] ?? null),
            'jenisKelamin'             => $this->str($row['ID_JENIS_KELAMIN'] ?? null),
            'kewarganegaraan'          => $this->wargaNegara($row),
            'negaraAsing'              => $this->str($row['ID_NEGARA_ASING'] ?? null),
            'agama'                    => $this->str($row['ID_JENIS_AGAMA'] ?? null),
            'agamaLain'                => $this->str($row['ID_JENIS_AGAMA_LAIN'] ?? null),
            'suku'                     => $this->str($row['ID_JENIS_SUKU'] ?? null),
            'sukuLain'                 => $this->str($row['ID_JENIS_SUKU_LAIN'] ?? null),
            'statusPerkawinan'         => $this->str($row['ID_JENIS_STATUS_PERKAWINAN'] ?? null),
            'tempatLahir'              => $wni ? $this->str($row['ID_TEMPAT_LAHIR'] ?? null) : '',
            'tempatLahirLain'          => $this->str($row['ID_TEMPAT_LAHIR_LAIN'] ?? null),
            'tempatAsal'               => $wni ? $this->str($row['ID_TEMPAT_ASAL'] ?? null) : '',
            'tempatAsalLain'           => $this->str($row['ID_TEMPAT_ASAL_LAIN'] ?? null),
            'tanggalLahir'             => DateFormat::toDisplay($row['TANGGAL_LAHIR'] ?? null, '/') ?? '',
            'beresikoTinggi'           => (int) ($row['IS_WBP_BERESIKO_TINGGI'] ?? 0) === 1,
            'pengaruhMasyarakat'       => (int) ($row['IS_PENGARUH_TERHADAP_MASYARAKAT'] ?? 0) === 1,
            'disabilitas'              => (int) ($row['IS_DISABILITAS'] ?? 0) === 1,
            'propinsi'                 => $wni ? $this->str($row['ID_PROPINSI'] ?? null) : '',
            'propinsiLain'             => $this->str($row['ID_PROPINSI_LAIN'] ?? null),
            'kota'                     => $wni ? $this->str($row['ID_KOTA'] ?? null) : '',
            'kotaLain'                 => $this->str($row['ID_KOTA_LAIN'] ?? null),
            'alamat'                   => $this->str($row['ALAMAT'] ?? null),
            'alamatAlternatif'         => $this->str($row['ALAMAT_ALTERNATIF'] ?? null),
            'telepon'                  => $this->str($row['TELEPON'] ?? null),
            'kodepos'                  => $this->str($row['KODEPOS'] ?? null),
            'jenisPekerjaan'           => $this->str($row['ID_JENIS_PEKERJAAN'] ?? null),
            'pekerjaanLain'            => $this->str($row['ID_JENIS_PEKERJAAN_LAIN'] ?? null),
            'namaInstansiPns'          => $this->str($row['NAMA_INSTANSI_PNS'] ?? null),
            'nip'                      => $this->str($row['NIP'] ?? null),
            'alamatPekerjaan'          => $this->str($row['ALAMAT_PEKERJAAN'] ?? null),
            'keteranganPekerjaan'      => $this->str($row['KETERANGAN_PEKERJAAN'] ?? null),
            'tingkatPenghasilan'       => $this->str($row['ID_TINGKAT_PENGHASILAN'] ?? null),
            'pendidikan'               => $this->str($row['ID_JENIS_PENDIDIKAN'] ?? null),
            'pendidikanLain'           => $this->str($row['ID_JENIS_PENDIDIKAN_LAIN'] ?? null),
            'keahlian1'                => $this->str($row['ID_JENIS_KEAHLIAN_1'] ?? null),
            'keahlian1Lain'            => $this->str($row['ID_JENIS_KEAHLIAN_1_LAIN'] ?? null),
            'level1'                   => $this->str($row['ID_JENIS_LEVEL_1'] ?? null),
            'keahlian2'                => $this->str($row['ID_JENIS_KEAHLIAN_2'] ?? null),
            'keahlian2Lain'            => $this->str($row['ID_JENIS_KEAHLIAN_2_LAIN'] ?? null),
            'level2'                   => $this->str($row['ID_JENIS_LEVEL_2'] ?? null),
            'minat'                    => $this->str($row['MINAT'] ?? null),
            'bacaLatin'                => (int) ($row['IS_BACA_LATIN'] ?? 0) === 1,
            'bacaQuran'                => (int) ($row['IS_BACA_QURAN'] ?? 0) === 1,
            'namaAyah'                 => $this->str($row['NM_AYAH'] ?? null),
            'alamatAyah'               => $this->str($row['TMP_TGL_AYAH'] ?? null),
            'namaIbu'                  => $this->str($row['NM_IBU'] ?? null),
            'alamatIbu'                => $this->str($row['TMP_TGL_IBU'] ?? null),
            'anakKe'                   => $this->str($row['ANAKKE'] ?? null),
            'jumlahSaudara'            => $this->str($row['JML_SAUDARA'] ?? null),
            'namaSaudara'              => $this->str($row['NM_SAUDARA'] ?? null),
            'jumlahIstriSuami'         => $this->str($row['JML_ISTRI_SUAMI'] ?? null),
            'namaIstriSuami'           => $this->str($row['NM_ISTRI_SUAMI'] ?? null),
            'alamatIstriSuami'         => $this->str($row['TMP_TGL_ISTRI_SUAMI'] ?? null),
            'jumlahAnak'               => $this->str($row['JML_ANAK'] ?? null),
            'namaAnak'                 => $this->str($row['NM_ANAK'] ?? null),
            'teleponKeluarga'          => $this->str($row['TELEPHONE_KELUARGA'] ?? null),
            'tinggi'                   => $this->str($row['TINGGI'] ?? null),
            'berat'                    => $this->str($row['BERAT'] ?? null),
            'bentukRambut'             => $this->str($row['ID_BENTUKRAMBUT'] ?? null),
            'warnaRambut'              => $this->str($row['ID_JENIS_RAMBUT'] ?? null),
            'bentukBibir'              => $this->str($row['ID_BENTUKBIBIR'] ?? null),
            'kacamata'                 => $this->str($row['ID_KACAMATA'] ?? null),
            'bentukMata'               => $this->str($row['ID_BENTUK_MATA'] ?? null),
            'warnaMata'                => $this->str($row['ID_WARNA_MATA'] ?? null),
            'hidung'                   => $this->str($row['ID_JENIS_HIDUNG'] ?? null),
            'rautMuka'                 => $this->str($row['ID_JENIS_MUKA'] ?? null),
            'telinga'                  => $this->str($row['ID_TELINGA'] ?? null),
            'mulut'                    => $this->str($row['ID_JENIS_MULUT'] ?? null),
            'lengan'                   => $this->str($row['ID_LENGAN'] ?? null),
            'tangan'                   => $this->str($row['ID_JENIS_TANGAN'] ?? null),
            'kaki'                     => $this->str($row['ID_JENIS_KAKI'] ?? null),
            'warnaKulit'               => $this->str($row['ID_WARNAKULIT'] ?? null),
            'cacat'                    => $this->str($row['CACAT'] ?? null),
            'ciri1'                    => $this->str($row['CIRI'] ?? null),
            'ciri2'                    => $this->str($row['CIRI2'] ?? null),
            'ciri3'                    => $this->str($row['CIRI3'] ?? null),
            'fotoKiriPath'             => $this->str($row['FOTO_KIRI'] ?? null),
            'fotoKananPath'            => $this->str($row['FOTO_KANAN'] ?? null),
            'fotoDepanPath'            => $this->str($row['FOTO_DEPAN'] ?? null),
            'fotoCloseupPath'          => $this->str($row['FOTO_CLOSEUP'] ?? null),
            'fotoCiri1Path'            => $this->str($row['FOTO_CIRI_1'] ?? null),
            'fotoCiri2Path'            => $this->str($row['FOTO_CIRI_2'] ?? null),
            'fotoCiri3Path'            => $this->str($row['FOTO_CIRI_3'] ?? null),
            'pengambilSidikJari'       => $this->str($sidik['PENGAMBIL_SJ'] ?? null),
            'noPaspor'                 => $this->str($sidik['NO_PASPOR'] ?? null),
            'rumusDaktil'              => $this->str($sidik['RUMUS_DAKTIL'] ?? null),
            'nomorDaktil'              => $this->str($sidik['NOMOR_DAKTIL'] ?? null),
            'tanggalSidikJari'         => DateFormat::toDisplay($sidik['TANGGAL_SJ'] ?? null, '/') ?? '',
            'idSidikJari'              => $this->str($sidik['ID_SIDIK_JARI'] ?? null),
            'nomorIndukSimilar'        => $similarIds,
        ];
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function lookups(): array
    {
        return [
            'agama'              => $this->lookup->referensiGroup('Agama'),
            'pendidikan'         => $this->lookup->referensiGroup('Pendidikan'),
            'kelamin'            => $this->lookup->referensiGroup('Kelamin'),
            'kewarganegaraan'    => $this->lookup->referensiGroup('Kewarganegaraan'),
            'perkawinan'         => $this->lookup->referensiGroup('Perkawinan'),
            'residivis'          => $this->lookup->referensiGroup('Residivis'),
            'keahlian'           => $this->lookup->referensiGroup('Keahlian'),
            'level'              => $this->lookup->referensiGroup('Level'),
            'penghasilan'        => $this->lookup->referensiGroup('Tingkat Penghasilan WBP'),
            'rambut'             => $this->lookup->referensiGroup('Rambut'),
            'bentukRambut'       => $this->lookup->referensiGroup('Bentuk Rambut'),
            'muka'               => $this->lookup->referensiGroup('Muka'),
            'hidung'             => $this->lookup->referensiGroup('Hidung'),
            'bentukMata'         => $this->lookup->referensiGroup('Bentuk Mata'),
            'warnaMata'          => $this->lookup->referensiGroup('Warna Mata'),
            'kaki'               => $this->lookup->referensiGroup('Kaki'),
            'tangan'             => $this->lookup->referensiGroup('Tangan'),
            'mulut'              => $this->lookup->referensiGroup('Mulut'),
            'kacamata'           => $this->lookup->referensiGroup('Berkacamata'),
            'bibir'              => $this->lookup->referensiGroup('Bibir'),
            'telinga'            => $this->lookup->referensiGroup('Telinga'),
            'lengan'             => $this->lookup->referensiGroup('Lengan'),
            'kulit'              => $this->lookup->referensiGroup('Kulit'),
            'suku'               => $this->lookup->sukuItems(),
            'pekerjaan'          => $this->lookup->pekerjaanItems(),
            'propinsi'           => $this->lookup->propinsiItems(),
            'negara'             => $this->lookup->negaraItems(),
            'dati2'              => $this->lookup->dati2Items(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string|null>
     */
    private function foto(array $row, string $legacy, string $nomorInduk, CurrentUser $user): array
    {
        $resolved = $this->photos->resolveRow($row, $nomorInduk, $user);

        return [
            'kiri'    => $this->mediaUrl($legacy, $resolved['FOTO_KIRI'] ?? null),
            'depan'   => $this->mediaUrl($legacy, $resolved['FOTO_DEPAN'] ?? null),
            'kanan'   => $this->mediaUrl($legacy, $resolved['FOTO_KANAN'] ?? null),
            'closeup' => $this->mediaUrl($legacy, $resolved['FOTO_CLOSEUP'] ?? null),
            'ciri1'   => $this->mediaUrl($legacy, $resolved['FOTO_CIRI_1'] ?? null),
            'ciri2'   => $this->mediaUrl($legacy, $resolved['FOTO_CIRI_2'] ?? null),
            'ciri3'   => $this->mediaUrl($legacy, $resolved['FOTO_CIRI_3'] ?? null),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function sidikJari(string $nomorInduk, string $legacy): array
    {
        $row = $this->identitas->findSidikJari($nomorInduk);

        if (! $row) {
            return [
                'jempolKanan' => null, 'telunjukKanan' => null, 'tengahKanan' => null,
                'manisKanan' => null, 'kelingkingKanan' => null,
                'jempolKiri' => null, 'telunjukKiri' => null, 'tengahKiri' => null,
                'manisKiri' => null, 'kelingkingKiri' => null,
            ];
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
        ];
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

    /**
     * @param array<string, mixed> $row
     */
    private function namaLengkapDisplay(array $row): string
    {
        $nama = $this->str($row['NAMA_LENGKAP'] ?? null);
        $ayah = $this->str($row['NM_AYAH'] ?? null);
        if ($ayah !== '' && ! preg_match('/BIN/i', $nama)) {
            return $nama . ' BIN ' . $ayah;
        }

        return $nama;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function wargaNegara(array $row): string
    {
        $value = $this->str($row['ID_JENIS_WARGANEGARA'] ?? null);

        return $value !== '' ? $value : 'WNI';
    }

    private function mediaUrl(string $legacy, ?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        return $legacy . '/' . ltrim($path, '/');
    }

    private function str(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
