<?php

namespace App\Modules\Identitas\Services;

use App\Modules\Auth\Services\RbacService;
use App\Modules\Identitas\Repositories\IdentitasRepository;
use App\Modules\Shared\Auth\CurrentUser;
use App\Modules\Shared\Database\UnitOfWork;
use App\Modules\Shared\Support\DateFormat;

final class IdentitasUpdateException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}

final class IdentitasUpdateService
{
    private const MAX_PHOTO_BYTES = 2097152;

    private const PHOTO_SLOTS = [
        'fotoKiri'    => ['column' => 'FOTO_KIRI',    'pathKey' => 'fotoKiriPath',    'file' => 'kiri',    'required' => true],
        'fotoKanan'   => ['column' => 'FOTO_KANAN',   'pathKey' => 'fotoKananPath',   'file' => 'kanan',   'required' => true],
        'fotoDepan'   => ['column' => 'FOTO_DEPAN',   'pathKey' => 'fotoDepanPath',   'file' => 'depan',   'required' => true],
        'fotoCloseup' => ['column' => 'FOTO_CLOSEUP', 'pathKey' => 'fotoCloseupPath', 'file' => 'closeup', 'required' => false],
        'fotoCiri1'   => ['column' => 'FOTO_CIRI_1',  'pathKey' => 'fotoCiri1Path',   'file' => 'cr1',     'required' => false],
        'fotoCiri2'   => ['column' => 'FOTO_CIRI_2',  'pathKey' => 'fotoCiri2Path',   'file' => 'cr2',     'required' => false],
        'fotoCiri3'   => ['column' => 'FOTO_CIRI_3',  'pathKey' => 'fotoCiri3Path',   'file' => 'cr3',     'required' => false],
    ];

    public function __construct(
        private readonly IdentitasRepository $identitas,
        private readonly RbacService $rbac,
        private readonly UnitOfWork $uow,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{nomorInduk: string}
     */
    public function update(string $nomorInduk, array $input, CurrentUser $user): array
    {
        $access = $this->rbac->accessFor($user, RbacService::MENU_IDENTITAS);
        if (! $access['canWrite']) {
            throw new IdentitasUpdateException(
                'Mohon maaf anda tidak berhak mengubah data identitas.',
                [],
                403,
            );
        }

        $existing = $this->identitas->find($nomorInduk);

        if (! $existing) {
            throw new IdentitasUpdateException('Identitas tidak ditemukan.', [], 404);
        }

        $errors = $this->validate($input, $existing);
        if ($errors !== []) {
            throw new IdentitasUpdateException('Data identitas belum lengkap.', $errors);
        }

        $photos = $this->resolvePhotos($nomorInduk, $input, $existing);
        $row    = $this->mapIdentitas($nomorInduk, $input, $photos, $user);
        $sidik  = $this->mapSidikJari($input);

        $this->uow->transactional(function () use ($nomorInduk, $existing, $row, $sidik, $input, $user): void {
            $this->writeChangeLog($nomorInduk, $existing, $row, $user);
            $this->identitas->update($nomorInduk, $row);
            $this->identitas->upsertSidikJari($nomorInduk, $sidik);
            $this->saveKemiripan($nomorInduk, (string) ($input['nomorIndukSimilar'] ?? ''));
        });

        return ['nomorInduk' => $nomorInduk];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, string>
     */
    private function validate(array $input, array $existing): array
    {
        $errors = [];
        if ($this->str($input['namaLengkap'] ?? '') === '') {
            $errors['namaLengkap'] = 'Nama lengkap wajib diisi.';
        }
        if ($this->str($input['alamat'] ?? '') === '') {
            $errors['alamat'] = 'Alamat wajib diisi.';
        }
        if (DateFormat::fromDisplay($this->str($input['tanggalLahir'] ?? '')) === null) {
            $errors['tanggalLahir'] = 'Tanggal lahir wajib diisi (dd/mm/yyyy).';
        }
        if ($this->str($input['namaAyah'] ?? '') === '') {
            $errors['namaAyah'] = 'Nama ayah wajib diisi.';
        }
        if ($this->str($input['namaIbu'] ?? '') === '') {
            $errors['namaIbu'] = 'Nama ibu wajib diisi.';
        }
        $jumlahAnak = $this->str($input['jumlahAnak'] ?? '');
        if ($jumlahAnak === '' || ! is_numeric($jumlahAnak)) {
            $errors['jumlahAnak'] = 'Jumlah anak wajib diisi (angka).';
        }

        $nik = $this->str($input['nik'] ?? '');
        if ($nik !== '' && (! ctype_digit($nik) || strlen($nik) !== 16)) {
            $errors['nik'] = 'NIK harus 16 digit angka.';
        }

        $tanggalSj = $this->str($input['tanggalSidikJari'] ?? '');
        if ($tanggalSj !== '' && DateFormat::fromDisplay($tanggalSj) === null) {
            $errors['tanggalSidikJari'] = 'Tanggal ambil sidik jari tidak valid.';
        }

        foreach (['tinggi' => 'Tinggi', 'berat' => 'Berat', 'anakKe' => 'Anak ke', 'jumlahSaudara' => 'Jumlah saudara', 'jumlahIstriSuami' => 'Jumlah istri/suami', 'kodepos' => 'Kode pos'] as $key => $label) {
            $value = $this->str($input[$key] ?? '');
            if ($value !== '' && ! is_numeric($value)) {
                $errors[$key] = $label . ' harus angka.';
            }
        }

        $ciriText = $this->str($input['ciri1'] ?? '') . $this->str($input['ciri2'] ?? '') . $this->str($input['ciri3'] ?? '');
        $hasCiriPhoto = false;
        foreach (['fotoCiri1', 'fotoCiri2', 'fotoCiri3'] as $slot) {
            $meta = self::PHOTO_SLOTS[$slot];
            if ($this->hasUpload($input[$slot] ?? null) || $this->existingFile($input[$meta['pathKey']] ?? ($existing[$meta['column']] ?? null))) {
                $hasCiriPhoto = true;
            }
        }
        if ($ciriText !== '' && ! $hasCiriPhoto) {
            $errors['ciri1'] = 'Jika identitas memiliki ciri khusus, foto ciri khusus wajib diisi.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, string>
     */
    private function resolvePhotos(string $nomorInduk, array $input, array $existing): array
    {
        $out = [];
        foreach (self::PHOTO_SLOTS as $slot => $meta) {
            $upload = $input[$slot] ?? null;
            if ($this->hasUpload($upload)) {
                $out[$meta['column']] = $this->storePhoto($nomorInduk, $meta['file'], $upload);
                continue;
            }

            $kept = $this->str($input[$meta['pathKey']] ?? ($existing[$meta['column']] ?? ''));
            if ($this->existingFile($kept)) {
                $out[$meta['column']] = $kept;
                continue;
            }

            if ($meta['required']) {
                $label = str_replace('foto', 'foto ', strtolower($slot));
                throw new IdentitasUpdateException('Silahkan isi ' . trim($label) . ' identitas.', [$slot => 'Foto wajib diisi.']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $photos
     * @return array<string, mixed>
     */
    private function mapIdentitas(string $nomorInduk, array $input, array $photos, CurrentUser $user): array
    {
        $wni = $this->str($input['kewarganegaraan'] ?? 'WNI') === 'WNI';

        $data = [
            'NOMOR_INDUK'                      => $nomorInduk,
            'NIK'                              => $this->str($input['nik'] ?? ''),
            'NAMA_LENGKAP'                     => $this->str($input['namaLengkap'] ?? ''),
            'TANGGAL_LAHIR'                    => DateFormat::fromDisplay($this->str($input['tanggalLahir'] ?? '')),
            'NAMA_ALIAS1'                      => $this->str($input['namaAlias1'] ?? ''),
            'NAMA_ALIAS2'                      => $this->str($input['namaAlias2'] ?? ''),
            'NAMA_ALIAS3'                      => $this->str($input['namaAlias3'] ?? ''),
            'NAMA_KECIL1'                      => $this->str($input['namaKecil1'] ?? ''),
            'NAMA_KECIL2'                      => $this->str($input['namaKecil2'] ?? ''),
            'NAMA_KECIL3'                      => $this->str($input['namaKecil3'] ?? ''),
            'ID_JENIS_KELAMIN'                 => $this->str($input['jenisKelamin'] ?? ''),
            'ID_JENIS_WARGANEGARA'             => $this->nullIfEmpty($input['kewarganegaraan'] ?? 'WNI') ?? 'WNI',
            'ID_NEGARA_ASING'                  => $this->str($input['negaraAsing'] ?? ''),
            'RESIDIVIS'                        => $this->str($input['residivis'] ?? ''),
            'RESIDIVIS_COUNTER'                => $this->str($input['residivisKe'] ?? ''),
            'IS_WBP_BERESIKO_TINGGI'           => $this->boolInt($input['beresikoTinggi'] ?? 0),
            'IS_PENGARUH_TERHADAP_MASYARAKAT'  => $this->boolInt($input['pengaruhMasyarakat'] ?? 0),
            'IS_DISABILITAS'                   => $this->boolInt($input['disabilitas'] ?? 0),
            'ID_JENIS_SUKU'                    => $this->nullIfEmpty($input['suku'] ?? ''),
            'ID_JENIS_SUKU_LAIN'               => $this->str($input['suku'] ?? '') === 'LAN' ? $this->str($input['sukuLain'] ?? '') : '',
            'ID_JENIS_AGAMA'                   => $this->nullIfEmpty($input['agama'] ?? ''),
            'ID_JENIS_AGAMA_LAIN'              => $this->str($input['agama'] ?? '') === 'LAIN' ? $this->str($input['agamaLain'] ?? '') : '',
            'ID_JENIS_STATUS_PERKAWINAN'       => $this->str($input['statusPerkawinan'] ?? ''),
            'ALAMAT'                           => $this->str($input['alamat'] ?? ''),
            'ALAMAT_ALTERNATIF'                => $this->str($input['alamatAlternatif'] ?? ''),
            'TELEPON'                          => $this->str($input['telepon'] ?? ''),
            'KODEPOS'                          => $this->str($input['kodepos'] ?? ''),
            'ID_JENIS_PEKERJAAN'               => $this->nullIfEmpty($input['jenisPekerjaan'] ?? ''),
            'ID_JENIS_PEKERJAAN_LAIN'          => $this->str($input['jenisPekerjaan'] ?? '') === '89' ? $this->str($input['pekerjaanLain'] ?? '') : '',
            'NAMA_INSTANSI_PNS'                => $this->str($input['jenisPekerjaan'] ?? '') === '5' ? $this->str($input['namaInstansiPns'] ?? '') : '',
            'NIP'                              => $this->str($input['jenisPekerjaan'] ?? '') === '5' ? $this->str($input['nip'] ?? '') : '',
            'ALAMAT_PEKERJAAN'                 => $this->str($input['alamatPekerjaan'] ?? ''),
            'KETERANGAN_PEKERJAAN'             => $this->str($input['keteranganPekerjaan'] ?? ''),
            'ID_TINGKAT_PENGHASILAN'           => $this->nullIfEmpty($input['tingkatPenghasilan'] ?? ''),
            'ID_JENIS_PENDIDIKAN'              => $this->nullIfEmpty($input['pendidikan'] ?? ''),
            'ID_JENIS_PENDIDIKAN_LAIN'         => $this->str($input['pendidikan'] ?? '') === 'PDLA' ? $this->str($input['pendidikanLain'] ?? '') : '',
            'ID_JENIS_KEAHLIAN_1'              => $this->nullIfEmpty($input['keahlian1'] ?? ''),
            'ID_JENIS_KEAHLIAN_1_LAIN'         => $this->str($input['keahlian1'] ?? '') === 'ZA99' ? $this->str($input['keahlian1Lain'] ?? '') : '',
            'ID_JENIS_LEVEL_1'                 => $this->nullIfEmpty($input['level1'] ?? ''),
            'ID_JENIS_KEAHLIAN_2'              => $this->nullIfEmpty($input['keahlian2'] ?? ''),
            'ID_JENIS_KEAHLIAN_2_LAIN'         => $this->str($input['keahlian2'] ?? '') === 'ZA99' ? $this->str($input['keahlian2Lain'] ?? '') : '',
            'ID_JENIS_LEVEL_2'                 => $this->nullIfEmpty($input['level2'] ?? ''),
            'MINAT'                            => $this->str($input['minat'] ?? ''),
            'IS_BACA_LATIN'                    => $this->boolInt($input['bacaLatin'] ?? 0),
            'IS_BACA_QURAN'                    => $this->boolInt($input['bacaQuran'] ?? 0),
            'NM_AYAH'                          => $this->str($input['namaAyah'] ?? ''),
            'TMP_TGL_AYAH'                     => $this->str($input['alamatAyah'] ?? ''),
            'NM_IBU'                           => $this->str($input['namaIbu'] ?? ''),
            'TMP_TGL_IBU'                      => $this->str($input['alamatIbu'] ?? ''),
            'ANAKKE'                           => $this->str($input['anakKe'] ?? ''),
            'JML_SAUDARA'                      => $this->str($input['jumlahSaudara'] ?? ''),
            'NM_SAUDARA'                       => $this->str($input['namaSaudara'] ?? ''),
            'JML_ISTRI_SUAMI'                  => $this->str($input['jumlahIstriSuami'] ?? ''),
            'NM_ISTRI_SUAMI'                   => $this->str($input['namaIstriSuami'] ?? ''),
            'TMP_TGL_ISTRI_SUAMI'              => $this->str($input['alamatIstriSuami'] ?? ''),
            'JML_ANAK'                         => $this->str($input['jumlahAnak'] ?? ''),
            'NM_ANAK'                          => $this->str($input['namaAnak'] ?? ''),
            'TELEPHONE_KELUARGA'               => $this->str($input['teleponKeluarga'] ?? ''),
            'TINGGI'                           => $this->str($input['tinggi'] ?? ''),
            'BERAT'                            => $this->str($input['berat'] ?? ''),
            'ID_BENTUKRAMBUT'                  => $this->nullIfEmpty($input['bentukRambut'] ?? ''),
            'ID_JENIS_RAMBUT'                  => $this->nullIfEmpty($input['warnaRambut'] ?? ''),
            'ID_BENTUKBIBIR'                   => $this->nullIfEmpty($input['bentukBibir'] ?? ''),
            'ID_KACAMATA'                      => $this->nullIfEmpty($input['kacamata'] ?? ''),
            'ID_BENTUK_MATA'                   => $this->nullIfEmpty($input['bentukMata'] ?? ''),
            'ID_WARNA_MATA'                    => $this->nullIfEmpty($input['warnaMata'] ?? ''),
            'ID_JENIS_HIDUNG'                  => $this->nullIfEmpty($input['hidung'] ?? ''),
            'ID_JENIS_MUKA'                    => $this->nullIfEmpty($input['rautMuka'] ?? ''),
            'ID_TELINGA'                       => $this->nullIfEmpty($input['telinga'] ?? ''),
            'ID_JENIS_MULUT'                   => $this->nullIfEmpty($input['mulut'] ?? ''),
            'ID_LENGAN'                        => $this->nullIfEmpty($input['lengan'] ?? ''),
            'ID_JENIS_TANGAN'                  => $this->nullIfEmpty($input['tangan'] ?? ''),
            'ID_JENIS_KAKI'                    => $this->nullIfEmpty($input['kaki'] ?? ''),
            'ID_WARNAKULIT'                    => $this->nullIfEmpty($input['warnaKulit'] ?? ''),
            'CACAT'                            => $this->str($input['cacat'] ?? ''),
            'CIRI'                             => $this->str($input['ciri1'] ?? ''),
            'CIRI2'                            => $this->str($input['ciri2'] ?? ''),
            'CIRI3'                            => $this->str($input['ciri3'] ?? ''),
            'ID_USER'                          => $user->id,
            'KONSOLIDASI'                      => 1,
            'KONSOLIDASI_IMAGE'                => 1,
        ];

        if ($wni) {
            $data['ID_PROPINSI']          = $this->nullIfEmpty($input['propinsi'] ?? '');
            $data['ID_KOTA']              = $this->nullIfEmpty($input['kota'] ?? '');
            $data['ID_TEMPAT_LAHIR']      = $this->nullIfEmpty($input['tempatLahir'] ?? '');
            $data['ID_TEMPAT_ASAL']       = $this->nullIfEmpty($input['tempatAsal'] ?? '');
            $data['ID_TEMPAT_LAHIR_LAIN'] = $this->str($input['tempatLahirLain'] ?? '');
        } else {
            $data['ID_PROPINSI_LAIN']     = $this->str($input['propinsiLain'] ?? '');
            $data['ID_TEMPAT_LAHIR_LAIN'] = $this->str($input['tempatLahirLain'] ?? '');
            $data['ID_TEMPAT_ASAL_LAIN']  = $this->str($input['tempatAsalLain'] ?? '');
            $data['ID_KOTA_LAIN']         = $this->str($input['kotaLain'] ?? '');
        }

        return array_merge($data, $photos);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mapSidikJari(array $input): array
    {
        return [
            'PENGAMBIL_SJ'  => $this->str($input['pengambilSidikJari'] ?? ''),
            'NO_PASPOR'     => $this->str($input['noPaspor'] ?? ''),
            'RUMUS_DAKTIL'  => $this->str($input['rumusDaktil'] ?? ''),
            'NOMOR_DAKTIL'  => $this->str($input['nomorDaktil'] ?? ''),
            'TANGGAL_SJ'    => DateFormat::fromDisplay($this->str($input['tanggalSidikJari'] ?? '')),
            'ID_SIDIK_JARI' => $this->nullIfEmpty($input['idSidikJari'] ?? ''),
            'KONSOLIDASI'   => 0,
        ];
    }

    private function saveKemiripan(string $nomorInduk, string $similarCsv): void
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', $similarCsv)), static fn ($id) => $id !== ''));
        $this->identitas->replaceKemiripan($nomorInduk, $ids);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $baru
     */
    private function writeChangeLog(string $nomorInduk, array $existing, array $baru, CurrentUser $user): void
    {
        $skip = ['ID_USER', 'KONSOLIDASI', 'TGL_ENTRY', 'KONSOLIDASI_IMAGE'];
        $now  = date('Y-m-d H:i:s');

        foreach ($baru as $field => $value) {
            if (in_array($field, $skip, true) || ! array_key_exists($field, $existing)) {
                continue;
            }

            $lama = (string) ($existing[$field] ?? '');
            $baruStr = $value === null ? '' : (string) $value;
            if ($lama === '0000-00-00' && $baruStr === '') {
                continue;
            }
            if ($lama === $baruStr) {
                continue;
            }

            $this->identitas->insertChangeLog([
                'NOMOR_INDUK' => $nomorInduk,
                'ID_USER'     => $user->id,
                'FIELD'       => $field,
                'ISI_LAMA'    => $lama,
                'ISI_BARU'    => $baruStr,
                'TGL_UPDATE'  => $now,
                'TABEL'       => 'identitas',
                'AKSI'        => 'UPDATE',
                'KONSOLIDASI' => '0',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $upload
     */
    private function storePhoto(string $nomorInduk, string $slot, array $upload): string
    {
        $raw = base64_decode((string) ($upload['base64'] ?? ''), true);
        if ($raw === false || $raw === '') {
            throw new IdentitasUpdateException('File foto rusak.', [$slot => 'File foto rusak.']);
        }
        if (strlen($raw) >= self::MAX_PHOTO_BYTES) {
            throw new IdentitasUpdateException('File foto yang anda upload lebih besar dari 2MB.', [$slot => 'Maksimal 2MB.']);
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            throw new IdentitasUpdateException('File foto yang anda upload rusak, harap gunakan file foto yang lain.', [$slot => 'Bukan file gambar.']);
        }

        $root = rtrim((string) env('legacy.root', ''), '/');
        if ($root === '') {
            $root = dirname(__DIR__, 5) . '/102sdp/sdp';
        }

        $relative = 'upload/' . date('Y') . '/' . date('m') . '/';
        $dir      = $root . '/' . $relative;
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new IdentitasUpdateException('Gagal menyimpan foto identitas.', [$slot => 'Folder upload tidak dapat dibuat.']);
        }

        $relativePath = $relative . $nomorInduk . '-' . $slot . '.jpg';
        if (file_put_contents($dir . $nomorInduk . '-' . $slot . '.jpg', $raw) === false) {
            throw new IdentitasUpdateException('Gagal menyimpan foto identitas.', [$slot => 'Gagal menulis file.']);
        }

        return $relativePath;
    }

    private function hasUpload(mixed $upload): bool
    {
        return is_array($upload) && isset($upload['base64']) && trim((string) $upload['base64']) !== '';
    }

    private function existingFile(mixed $path): bool
    {
        return $this->str($path) !== '';
    }

    private function boolInt(mixed $value): int
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true') {
            return 1;
        }

        return 0;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        $value = $this->str($value);

        return $value === '' ? null : $value;
    }

    private function str(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
