<?php

namespace App\Modules\Perkara\Services;

use App\Modules\Perkara\Repositories\EkspirasiRepository;

/**
 * EkspirasiCalculatorService
 *
 * Port 1:1 dari legacy Func_ekspirasi.php (CI2, 4729 baris) ke CI4 / PHP 8.
 *
 * Kelas ini bertanggung jawab atas seluruh perhitungan masa pidana:
 * - Tanggal Ekspirasi (tanggal bebas)
 * - Tanggal 1/3, 1/2, 2/3 masa pidana
 * - Potong Tahanan (potah)
 * - Remisi dan Grasi
 * - Subsider (denda, uang pengganti, restitusi)
 * - MAP (multi-perkara / perkara gabungan)
 * - Sisa pidana pencabutan PB
 *
 * CATATAN PENTING:
 * - Semua rumusan matematika hukum diport PERSIS dari legacy.
 * - Tanggal dalam format internal menggunakan dd/mm/yyyy (slash) atau yyyy-mm-dd (DB).
 * - 1 bulan = 30 hari (SE Tahun 77 no.D.P.1.1/1a/16).
 * - Overlap penahanan dihitung dengan rumus baru (is_rumus_baru) atau lama.
 *
 * @see d:\_development\sdp_upt\102sdp\system\application\libraries\Func_ekspirasi.php
 */
final class EkspirasiCalculatorService
{
    private EkspirasiRepository $repository;

    // ==========================================
    // Properties (port dari legacy var declarations)
    // ==========================================

    // Penangkapan
    private string $tanggalAwalPenangkapan = '';
    private int $tahunPenangkapan = 0;
    private int $bulanPenangkapan = 0;
    private int $tanggalPenangkapan = 0;

    // Awal Penahanan
    private int $tahunAwalPenahanan = 0;
    private int $bulanAwalPenahanan = 0;
    private int $tanggalAwalPenahanan = 0;

    // Hukuman
    private int $tahunHukuman = 0;
    private int $bulanHukuman = 0;
    private int $hariHukuman = 0;

    // Putusan
    private int $tahunPutusan = 0;
    private int $bulanPutusan = 0;
    private int $hariPutusan = 0;

    // Potah (Potong Tahanan)
    private int $tahunPotah = 0;
    private int $bulanPotah = 0;
    private int $hariPotah = 0;

    // Hari di luar UPT (melarikan diri / tahanan luar)
    private int $hariDiluarUpt = 0;

    // Sisa pidana pencabutan PB
    private int $tahunCabutPb = 0;
    private int $bulanCabutPb = 0;
    private int $hariCabutPb = 0;

    // Subsider Denda
    private int $tahunSubDenda = 0;
    private int $bulanSubDenda = 0;
    private int $hariSubDenda = 0;

    // Subsider Uang Pengganti
    private int $tahunSubUp = 0;
    private int $bulanSubUp = 0;
    private int $hariSubUp = 0;

    // Subsider Restitusi
    private int $tahunSubRestitusi = 0;
    private int $bulanSubRestitusi = 0;
    private int $hariSubRestitusi = 0;

    // Tanggal lepas (hasil perhitungan)
    private int $tahunLepas = 0;
    private int $bulanLepas = 0;
    private int $tglLepas = 0;
    private string $fullDateLepasDb = '';

    // Tanggal lepas + subsider
    private int $tahunLepasSubsider = 0;
    private int $bulanLepasSubsider = 0;
    private int $tglLepasSubsider = 0;
    private string $tglEkspirasiSubsiderTanpaRemisi = '';

    // Tanggal lepas + subsider + UP
    private int $tahunLepasSubsiderUp = 0;
    private int $bulanLepasSubsiderUp = 0;
    private int $tglLepasSubsiderUp = 0;
    private string $tglEkspirasiSubsiderUpTanpaRemisi = '';

    // Tanggal lepas + subsider + Restitusi
    private int $tahunLepasSubsiderRestitusi = 0;
    private int $bulanLepasSubsiderRestitusi = 0;
    private int $tglLepasSubsiderRestitusi = 0;
    private string $tglEkspirasiSubsiderRestitusiTanpaRemisi = '';

    // Remisi
    private int $jmlRemisi = 0;
    private int $jmlRemisiDasawarsaLuarbiasa = 0;
    private array $remisi = [];
    private array $remisiDasawarsaLuarbiasa = [];
    private ?string $remisiRdBiiiRu2 = null;
    private ?string $remisiRdBiiiRu2FlagSubsider = null;
    private string $tglEkspirasiTanpaRemisi = '';

    // Potah detail (untuk overlap calculation)
    private array $potah = [];
    private array $potahDetail = [];
    private int $jmlPotah = 0;

    // Overlap tracking
    private int $gJmlTglMulaiOverlap = 0;
    private string $gTglAkhirOverlap = '';
    private string $gJenisIdOverlap = '';
    private string $gJenisOverlap = '';
    private string $gTglAkhirOverlap2 = '';
    private string $gJenisIdOverlap2 = '';
    private string $gJenisOverlap2 = '';

    // Hukuman array (untuk MAP)
    private array $tahunHuk = [];
    private array $bulanHuk = [];
    private array $hariHuk = [];
    private int $jmlHuk = 0;

    // Rumus baru flag
    private bool $isRumusBaru = false;

    // Register yang dikecualikan dari potah (tahanan, militer, anak)
    private const REG_TAHANAN_EXCLUDE = ['AI', 'AII', 'AITER', 'AIITER', 'AIII', 'AIV', 'AV', 'AMIL', 'C', 'RTA'];

    // ==========================================
    // Constructor
    // ==========================================

    public function __construct(?EkspirasiRepository $repository = null)
    {
        $this->repository = $repository ?? new EkspirasiRepository();
        $this->kosongkan();
    }

    // ==========================================
    // ENTRY POINT UTAMA
    // Port dari: Func_ekspirasi::calculator_ekspirasi()
    // Legacy line: 3934-4727
    // ==========================================

    /**
     * Hitung ekspirasi untuk perkara tertentu — entry point utama.
     * Port dari: calculator_ekspirasi (legacy line 3934-4727)
     *
     * Untuk konsolidasi handler (server-side), cukup berikan $idPerkara.
     *
     * @param string|null $idPerkara ID Perkara
     * @param string|null $idReg     Jenis registrasi (BI, BII, BIII, AI, AII, dst)
     * @param string      $type      'non-calculator' | 'calculator' | 'registrasi'
     * @param string      $view      'default' | 'plain' | 'default_spptti'
     *
     * @return array Hasil perhitungan:
     *   - 'hasil_awal'  => tanggal ekspirasi awal (Y-m-d)
     *   - 'hasil'       => tanggal ekspirasi akhir (Y-m-d)
     *   - 'hasil1_3'    => tanggal 1/3 masa pidana
     *   - 'hasil1_2'    => tanggal 1/2 masa pidana
     *   - 'hasil2_3'    => tanggal 2/3 masa pidana
     *   - 'tgl_ekspirasi_tanpa_remisi' => ekspirasi sebelum remisi
     *   - 'potah'       => array detail potah
     *   - 'remisi'      => array detail remisi
     */
    public function calculatorEkspirasi(?string $idPerkara = null, ?string $idReg = null, string $type = 'non-calculator', string $view = 'default'): array
    {
        // 1. Reset state
        $this->kosongkan();

        if (empty($idPerkara)) {
            return [];
        }

        // 2. Load data perkara
        $perkara = $this->repository->getPerkaraById($idPerkara);
        if (empty($perkara)) {
            return [];
        }

        // 3. Tentukan tipe registrasi jika tidak diberikan
        $regGol = $idReg ?? $perkara['NO_REG_GOL'];

        // 4. Set hukuman
        $this->setHukuman(
            (int) ($perkara['TAHUN_HUKUMAN_AKHIR'] ?? 0),
            (int) ($perkara['BULAN_HUKUMAN_AKHIR'] ?? 0),
            (int) ($perkara['HARI_HUKUMAN_AKHIR'] ?? 0)
        );

        // Set subsider denda
        $this->setSubsiderDenda(
            (int) ($perkara['THN_SUB_DENDA_AKHIR'] ?? 0),
            (int) ($perkara['BLN_SUB_DENDA_AKHIR'] ?? 0),
            (int) ($perkara['HR_SUB_DENDA_AKHIR'] ?? 0)
        );

        // Set subsider UP
        $this->setSubsiderUp(
            (int) ($perkara['THN_SUB_UP_AKHIR'] ?? 0),
            (int) ($perkara['BLN_SUB_UP_AKHIR'] ?? 0),
            (int) ($perkara['HR_SUB_UP_AKHIR'] ?? 0)
        );

        // Set subsider restitusi
        $this->setSubsiderRestitusi(
            (int) ($perkara['THN_SUB_RESTITUSI_AKHIR'] ?? 0),
            (int) ($perkara['BLN_SUB_RESTITUSI_AKHIR'] ?? 0),
            (int) ($perkara['HR_SUB_RESTITUSI_AKHIR'] ?? 0)
        );

        // Set putusan (tanggal mulai hitung, atau tanggal awal penahanan untuk tahanan)
        if (!empty($perkara['TGL_PUTUSAN_AKHIR'])) {
            $tglPut = $perkara['TGL_PUTUSAN_AKHIR'];
            $pp = explode('-', $tglPut);
            if (count($pp) === 3) {
                $this->setPutusan($pp[2] . '/' . $pp[1] . '/' . $pp[0]);
            }
        }

        // Tanggal awal penahanan
        if (!empty($perkara['TGL_AWAL_PENYIDIKAN'])) {
            $this->setTanggalPenangkapan($this->convertDate('-', $perkara['TGL_AWAL_PENYIDIKAN'], '/'));
        }
        if (!empty($perkara['TGL_AWAL_PENAHANAN'])) {
            $this->setAwalPenahananFromDb($perkara['TGL_AWAL_PENAHANAN']);
        }

        // Sisa pencabutan PB
        $this->setPencabutanPb(
            (int) ($perkara['THN_PENCABUTAN_PB'] ?? 0),
            (int) ($perkara['BLN_PENCABUTAN_PB'] ?? 0),
            (int) ($perkara['HR_PENCABUTAN_PB'] ?? 0)
        );

        // Hari di luar UPT
        $hariDiluar = $this->repository->getTotalTahananLuar($idPerkara);
        $this->setHariDiluarUpt((int) $hariDiluar);

        // 5. Cek apakah rumus baru (potah menggunakan array penahanan)
        $isRegistrasiTahanan = in_array($regGol, self::REG_TAHANAN_EXCLUDE);
        if (!$isRegistrasiTahanan && !empty($perkara['TGL_PUTUSAN_AKHIR'])) {
            $this->isRumusBaru = true;
        }

        // 6. Load MAP (multi-perkara)
        $mapParent = $this->repository->getMapParent($idPerkara);
        if (!empty($mapParent)) {
            // Perkara ini adalah bagian dari MAP — load hukuman dari parent
            $mapParentPerkara = $this->repository->getPerkaraById($mapParent['ID_PERKARA_UTAMA']);
            if (!empty($mapParentPerkara)) {
                $this->tahunHuk[] = (int) ($mapParentPerkara['TAHUN_HUKUMAN_AKHIR'] ?? 0);
                $this->bulanHuk[] = (int) ($mapParentPerkara['BULAN_HUKUMAN_AKHIR'] ?? 0);
                $this->hariHuk[] = (int) ($mapParentPerkara['HARI_HUKUMAN_AKHIR'] ?? 0);
                $this->jmlHuk++;
            }
        }
        $mapChildren = $this->repository->getMapChildren($idPerkara);
        foreach ($mapChildren as $child) {
            $this->tahunHuk[] = (int) ($child['TAHUN_HUKUMAN_AKHIR'] ?? 0);
            $this->bulanHuk[] = (int) ($child['BULAN_HUKUMAN_AKHIR'] ?? 0);
            $this->hariHuk[] = (int) ($child['HARI_HUKUMAN_AKHIR'] ?? 0);
            $this->jmlHuk++;
        }

        // 7. Load penahanan dan potah
        if ($this->isRumusBaru) {
            $this->getPenahananArray($idPerkara);
            $this->recalculatePenahanan();
        } else {
            // Rumus lama: potah dari field langsung di perkara
            $this->setPotah(
                (int) ($perkara['TAHUN_POTAH'] ?? 0),
                (int) ($perkara['BULAN_POTAH'] ?? 0),
                (int) ($perkara['HARI_POTAH'] ?? 0)
            );
        }

        // 8. Load remisi
        if (!$isRegistrasiTahanan) {
            $this->getRemisiArray($idPerkara);
        }

        // 9. Hitung ekspirasi awal (tanpa remisi)
        $hasilAwal = $this->getEkspirasiSemula('Y-m-d');

        // 10. Hitung ekspirasi akhir (dengan remisi + subsider)
        $hasil = $this->getEkspirasi('Y-m-d');

        // 11. Hitung 1/3, 1/2, 2/3
        $totalHukuman = [
            'tahun' => $this->tahunHukuman + array_sum($this->tahunHuk) + $this->tahunCabutPb,
            'bulan' => $this->bulanHukuman + array_sum($this->bulanHuk) + $this->bulanCabutPb,
            'hari'  => $this->hariHukuman + array_sum($this->hariHuk) + $this->hariCabutPb,
        ];

        $totalRemisi = ['bulan' => 0, 'hari' => 0];
        foreach ($this->remisi as $r) {
            $totalRemisi['bulan'] += ($r['bulan_remisi_sebenarnya'] ?? 0);
            $totalRemisi['hari'] += ($r['hari_remisi_sebenarnya'] ?? 0);
        }

        $tglAwalPenahanan = !empty($perkara['TGL_AWAL_PENAHANAN']) ? $perkara['TGL_AWAL_PENAHANAN'] : '';

        $hasil1_3 = '';
        $hasil1_2 = '';
        $hasil2_3 = '';

        if (!empty($tglAwalPenahanan) && !empty($totalHukuman['tahun'] + $totalHukuman['bulan'] + $totalHukuman['hari'])) {
            $hasil1_3 = $this->hitungTglSeper('sepertiga', $tglAwalPenahanan, $totalHukuman, $totalRemisi, $this->hariDiluarUpt, false, 0, !empty($this->tahunCabutPb || $this->bulanCabutPb || $this->hariCabutPb));
            $hasil1_2 = $this->hitungTglSeper('setengah', $tglAwalPenahanan, $totalHukuman, $totalRemisi, $this->hariDiluarUpt, false, 0, !empty($this->tahunCabutPb || $this->bulanCabutPb || $this->hariCabutPb));
            $hasil2_3 = $this->hitungTglSeper('duapertiga', $tglAwalPenahanan, $totalHukuman, $totalRemisi, $this->hariDiluarUpt, false, 0, !empty($this->tahunCabutPb || $this->bulanCabutPb || $this->hariCabutPb));
        }

        return [
            'id_perkara'                        => $idPerkara,
            'no_reg_gol'                        => $regGol,
            'hasil_awal'                        => $hasilAwal,
            'hasil'                             => $hasil,
            'hasil1_3'                          => $hasil1_3,
            'hasil1_2'                          => $hasil1_2,
            'hasil2_3'                          => $hasil2_3,
            'tgl_ekspirasi_tanpa_remisi'        => $this->tglEkspirasiTanpaRemisi,
            'tgl_ekspirasi_subsider'            => $this->getEkspirasiPlusSubsider('Y-m-d'),
            'tgl_ekspirasi_subsider_up'         => $this->getEkspirasiPlusSubsiderPlusUp('Y-m-d'),
            'tgl_ekspirasi_subsider_restitusi'  => $this->getEkspirasiPlusSubsiderPlusUpPlusRestitusi('Y-m-d'),
            'tgl_ekspirasi_subsider_tanpa_remisi'           => $this->tglEkspirasiSubsiderTanpaRemisi,
            'tgl_ekspirasi_subsider_up_tanpa_remisi'        => $this->tglEkspirasiSubsiderUpTanpaRemisi,
            'tgl_ekspirasi_subsider_restitusi_tanpa_remisi' => $this->tglEkspirasiSubsiderRestitusiTanpaRemisi,
            'potah'                             => $this->potah,
            'potah_detail'                      => $this->potahDetail,
            'remisi'                            => $this->remisi,
            'hari_diluar_upt'                   => $this->hariDiluarUpt,
            'is_rumus_baru'                     => $this->isRumusBaru,
        ];
    }

    // ==========================================
    // SETTER METHODS
    // Port dari legacy setter functions (line 119-305)
    // ==========================================

    /**
     * Set tanggal awal penahanan dari format dd/mm/yyyy.
     * Port dari: set_awal_penahanan (line 119)
     */
    public function setAwalPenahanan(string $tanggalPenahanan): void
    {
        if (!empty($this->tanggalAwalPenangkapan)) {
            $this->tahunAwalPenahanan = $this->tahunPenangkapan;
            $this->bulanAwalPenahanan = $this->bulanPenangkapan;
            $this->tanggalAwalPenahanan = $this->tanggalPenangkapan;
        } else {
            if (!empty($tanggalPenahanan)) {
                $parts = explode('/', $tanggalPenahanan);
                if (count($parts) === 3) {
                    $this->tahunAwalPenahanan = (int) $parts[2];
                    $this->bulanAwalPenahanan = (int) $parts[1];
                    $this->tanggalAwalPenahanan = (int) $parts[0];
                }
            }
        }
    }

    /**
     * Set tanggal penangkapan dari format dd/mm/yyyy.
     * Port dari: set_tanggal_penangkapan (line 143)
     */
    public function setTanggalPenangkapan(?string $tanggalPenangkapan): void
    {
        if (!empty($tanggalPenangkapan)) {
            $this->tanggalAwalPenangkapan = $tanggalPenangkapan;
            $parts = explode('/', $tanggalPenangkapan);
            if (count($parts) === 3) {
                $this->tahunPenangkapan = (int) $parts[2];
                $this->bulanPenangkapan = (int) $parts[1];
                $this->tanggalPenangkapan = (int) $parts[0];
            }
        }
    }

    /**
     * Set tanggal putusan dari format dd/mm/yyyy.
     * Port dari: set_putusan (line 158)
     */
    public function setPutusan(?string $tglPutusan): void
    {
        if (!empty($tglPutusan)) {
            $parts = explode('/', $tglPutusan);
            if (count($parts) !== 3) {
                // Fallback ke tanggal awal penahanan jika putusan tidak valid
                $this->tahunPutusan = $this->tahunAwalPenahanan;
                $this->bulanPutusan = $this->bulanAwalPenahanan;
                $this->hariPutusan = $this->tanggalAwalPenahanan;
                return;
            }
            if ((empty($parts[0]) && empty($parts[1]) && empty($parts[2]))
                || ($parts[0] === '00' && $parts[1] === '00')) {
                $this->tahunPutusan = $this->tahunAwalPenahanan;
                $this->bulanPutusan = $this->bulanAwalPenahanan;
                $this->hariPutusan = $this->tanggalAwalPenahanan;
                return;
            }
            $this->tahunPutusan = (int) $parts[2];
            $this->bulanPutusan = (int) $parts[1];
            $this->hariPutusan = (int) $parts[0];
        }
    }

    /**
     * Set tanggal awal penahanan dari format database yyyy-mm-dd.
     * Port dari: set_awal_penahanan_from_db (line 192)
     */
    public function setAwalPenahananFromDb(?string $tanggalPenahanan): void
    {
        if (!empty($tanggalPenahanan)) {
            $parts = explode('-', $tanggalPenahanan);
            if (count($parts) === 3) {
                $this->tahunAwalPenahanan = (int) $parts[0];
                $this->bulanAwalPenahanan = (int) $parts[1];
                $this->tanggalAwalPenahanan = (int) $parts[2];
            }
        }
    }

    /**
     * Set masa potong tahanan.
     * Port dari: set_potah (line 207)
     */
    public function setPotah(int $tahun = 0, int $bulan = 0, int $hari = 0): void
    {
        $this->tahunPotah = $tahun ?: 0;
        $this->bulanPotah = $bulan ?: 0;
        $this->hariPotah = $hari ?: 0;
    }

    /**
     * Set masa hukuman.
     * Port dari: set_hukuman (line 227)
     */
    public function setHukuman(int $tahun = 0, int $bulan = 0, int $hari = 0): void
    {
        $this->tahunHukuman = $tahun ?: 0;
        $this->bulanHukuman = $bulan ?: 0;
        $this->hariHukuman = $hari ?: 0;
    }

    /**
     * Set subsider denda.
     * Port dari: set_subsider_denda (line 247)
     */
    public function setSubsiderDenda(int $tahun = 0, int $bulan = 0, int $hari = 0): void
    {
        $this->tahunSubDenda = $tahun ?: 0;
        $this->bulanSubDenda = $bulan ?: 0;
        $this->hariSubDenda = $hari ?: 0;
    }

    /**
     * Set subsider uang pengganti.
     * Port dari: set_subsider_up (line 267)
     */
    public function setSubsiderUp(int $tahun = 0, int $bulan = 0, int $hari = 0): void
    {
        $this->tahunSubUp = $tahun ?: 0;
        $this->bulanSubUp = $bulan ?: 0;
        $this->hariSubUp = $hari ?: 0;
    }

    /**
     * Set subsider restitusi.
     * Port dari: set_subsider_restitusi (line 287)
     */
    public function setSubsiderRestitusi(int $tahun = 0, int $bulan = 0, int $hari = 0): void
    {
        $this->tahunSubRestitusi = $tahun ?: 0;
        $this->bulanSubRestitusi = $bulan ?: 0;
        $this->hariSubRestitusi = $hari ?: 0;
    }

    /**
     * Set hari di luar UPT.
     * Port dari: set_hari_diluar_upt (line 1461)
     */
    public function setHariDiluarUpt(int $hariDiluar): void
    {
        $this->hariDiluarUpt = $hariDiluar;
    }

    /**
     * Set sisa pidana pencabutan PB.
     * Port dari: set_pencabutan_pb (line 1466)
     */
    public function setPencabutanPb(int $tahun = 0, int $bulan = 0, int $hari = 0): void
    {
        $this->tahunCabutPb = !empty($tahun) ? $tahun : 0;
        $this->bulanCabutPb = !empty($bulan) ? $bulan : 0;
        $this->hariCabutPb = !empty($hari) ? $hari : 0;
    }

    // ==========================================
    // PENAHANAN / POTAH METHODS
    // Port dari legacy line 307-946
    // ==========================================

    /**
     * Tambahkan record penahanan ke array potah.
     * Port dari: set_penahanan (line 307)
     */
    public function setPenahanan(string $jenisId, string $jenis, string $tglMulai = '', string $tglSelesai = '', bool $mysqlDate = false, bool $loadDb = false): void
    {
        $result = $this->hitungPenahanan($jenisId, $jenis, $tglMulai, $tglSelesai, $mysqlDate);

        $this->potah[] = [
            'JENIS_ID'       => $result['JENIS_ID'],
            'JENIS'          => $result['JENIS'],
            'PRIORITAS'      => $result['PRIORITAS'],
            'TGL_MULAI'      => $result['TGL_MULAI'],
            'TGL_SELESAI'    => $result['TGL_SELESAI'],
            'TGL_MULAI_REAL' => $result['TGL_MULAI'],
            'TGL_SELESAI_REAL' => $result['TGL_SELESAI'],
            'LAMA_TAHAN'     => $result['LAMA_TAHAN'],
            'LAMA_POTAH'     => $result['LAMA_POTAH'],
            'LOAD_DB'        => $loadDb,
        ];
        $this->jmlPotah++;
    }

    /**
     * Hitung lama penahanan berdasarkan jenis dan tanggal.
     * Port dari: hitung_penahanan (line 327)
     *
     * Jenis penahanan:
     * - PENAHANAN, PA1-PA5, PC1, PAM1, PENAHANAN_LANJUTAN: potah = lama_tahan (prioritas 1-2)
     * - STL1 (Tahanan Kota): potah = round(lama_tahan / 5) (prioritas 3)
     * - STL2 (Tahanan Rumah): potah = round(lama_tahan / 3) (prioritas 3)
     * - STL3 (Pembantaran), STL4 (Penangguhan): potah = 0, lama_tahan negatif (prioritas 4)
     */
    public function hitungPenahanan(string $jenisId, string $jenis, string $tglMulai = '', string $tglSelesai = '', bool $mysqlDate = false): array
    {
        $lamaTahan = 0;
        $lamaPotah = 0;
        $prioritas = '';

        if (!empty($tglMulai) && !empty($tglSelesai)) {
            if (!$mysqlDate) {
                $parts = explode('/', $tglMulai);
                $tglMulai = (count($parts) === 3) ? $parts[2] . $parts[1] . $parts[0] : '';

                $parts = explode('/', $tglSelesai);
                $tglSelesai = (count($parts) === 3) ? $parts[2] . $parts[1] . $parts[0] : '';
            }

            if (!empty($tglMulai) && !empty($tglSelesai)) {
                if ($tglMulai > $tglSelesai) {
                    $lamaTahan = 0;
                    $lamaPotah = 0;
                } else {
                    // Menggunakan SQL DATEDIFF (identik dengan legacy)
                    $lamaTahan = $this->repository->dateDiff($tglSelesai, $tglMulai);
                    $lamaTahan = ($lamaTahan > 0) ? $lamaTahan : 0;
                    $lamaTahan++;

                    switch ($jenisId) {
                        case 'STL2': // Tahanan Rumah 1/3
                            $lamaTahan--;
                            $lamaPotah = (int) round($lamaTahan / 3);
                            $prioritas = 3;
                            break;
                        case 'STL1': // Tahanan Kota 1/5
                            $lamaTahan--;
                            $lamaPotah = (int) round($lamaTahan / 5);
                            $prioritas = 3;
                            break;
                        case 'STL3': // Pembantaran
                        case 'STL4': // Penangguhan
                            $lamaPotah = 0;
                            $lamaTahan = -$lamaTahan + 1;
                            $prioritas = 4;
                            break;
                        case 'PENAHANAN':
                            $lamaPotah = $lamaTahan;
                            $prioritas = 1;
                            break;
                        case 'PA1':
                        case 'PA1T':
                        case 'PA2T':
                        case 'PA2':
                        case 'PA3':
                        case 'PA4':
                        case 'PA5':
                        case 'PC1':
                        case 'PAM1':
                        case 'PENAHANAN_LANJUTAN':
                            $lamaPotah = $lamaTahan;
                            $prioritas = 2;
                            break;
                    }
                }
            }
        }

        return [
            'JENIS_ID'       => $jenisId,
            'JENIS'          => $jenis,
            'PRIORITAS'      => $prioritas,
            'TGL_MULAI'      => $tglMulai,
            'TGL_SELESAI'    => $tglSelesai,
            'TGL_MULAI_REAL' => $tglMulai,
            'TGL_SELESAI_REAL' => $tglSelesai,
            'LAMA_TAHAN'     => $lamaTahan,
            'LAMA_POTAH'     => $lamaPotah,
        ];
    }

    /**
     * Identifikasi apakah dua jenis penahanan sejenis (untuk overlap detection).
     * Port dari: check_penahanan_likeness (line 496)
     */
    public function checkPenahananLikeness(string $type1, string $type2): bool
    {
        $penahananType = [
            'PENAHANAN' => 1, 'PA1' => 1, 'PA1T' => 1, 'PA2T' => 1,
            'PA2' => 1, 'PA3' => 1, 'PA4' => 1, 'PA5' => 1, 'PC1' => 1, 'PAM1' => 1,
            'STL1' => 2, 'STL2' => 2,
            'STL3' => 3, 'STL4' => 3,
        ];

        return ($penahananType[$type1] ?? 0) === ($penahananType[$type2] ?? 0);
    }

    // ==========================================
    // REMISI METHODS
    // Port dari legacy line 1314-1460
    // ==========================================

    /**
     * Tambahkan remisi ke array.
     * Port dari: set_remisi (line 1314)
     */
    public function setRemisi(int $tahun = 0, int $bulan = 0, int $hari = 0, ?string $tglPeruntukkan = null, string $jenisRemisiGrasi = 'REMISI', bool $loadDb = false, array $params = []): void
    {
        $this->remisi[] = [
            'tahun_remisi'              => $tahun ?: 0,
            'bulan_remisi'              => $bulan ?: 0,
            'hari_remisi'               => $hari ?: 0,
            'tahun_remisi_sebenarnya'   => $tahun ?: 0,
            'bulan_remisi_sebenarnya'   => $bulan ?: 0,
            'hari_remisi_sebenarnya'    => $hari ?: 0,
            'tgl_peruntukkan'           => $tglPeruntukkan ?? '',
            'jenis_remisi_grasi'        => $jenisRemisiGrasi,
            'load_db'                   => $loadDb,
            'params'                    => $params,
        ];
        $this->jmlRemisi++;
    }

    /**
     * Tambahkan remisi dasawarsa / luar biasa.
     * Port dari: set_remisi_dasawarsa_luarbiasa (line 1354)
     */
    public function setRemisiDasawarsaLuarbiasa(int $tahun = 0, int $bulan = 0, int $hari = 0, ?string $tglPeruntukkan = null, string $jenisRemisiGrasi = 'REMISI', bool $loadDb = false): void
    {
        $this->remisiDasawarsaLuarbiasa[] = [
            'tahun_remisi'              => $tahun ?: 0,
            'bulan_remisi'              => $bulan ?: 0,
            'hari_remisi'               => $hari ?: 0,
            'tahun_remisi_sebenarnya'   => $tahun ?: 0,
            'bulan_remisi_sebenarnya'   => $bulan ?: 0,
            'hari_remisi_sebenarnya'    => $hari ?: 0,
            'tgl_peruntukkan'           => $tglPeruntukkan ?? '',
            'jenis_remisi_grasi'        => $jenisRemisiGrasi,
            'load_db'                   => $loadDb,
        ];
        $this->jmlRemisiDasawarsaLuarbiasa++;
    }

    // ==========================================
    // UTILITY / HELPER METHODS
    // Port dari legacy line 2152-2428
    // ==========================================

    /**
     * Hitung jumlah hari dari tahun + bulan + hari.
     * 1 tahun = 12 bulan. 1 bulan = 30 hari.
     * Port dari: hitung_jumlah_hari (line 2290)
     */
    public function hitungJumlahHari(int $tahun = 0, int $bulan = 0, int $hari = 0, bool $perhitunganBulan = false): int
    {
        if ($perhitunganBulan) {
            return ($tahun * 12 * 30) + ($bulan * 30) + $hari;
        }
        return ($tahun * 360) + ($bulan * 30) + $hari;
    }

    /**
     * Konversi jumlah hari ke tahun, bulan, hari.
     * Port dari: hitung_hari_bulan_tahun (line 2302)
     */
    public function hitungHariBulanTahun(int $jumlahHari, bool $conversiTahun = true, bool $perhitunganBulan = false): array
    {
        if ($conversiTahun) {
            $tahun = intdiv($jumlahHari, 360);
            $sisa = $jumlahHari % 360;
        } else {
            $tahun = 0;
            $sisa = $jumlahHari;
        }

        $bulan = intdiv($sisa, 30);
        $hari = $sisa % 30;

        return ['tahun' => $tahun, 'bulan' => $bulan, 'hari' => $hari];
    }

    /**
     * Konversi tanggal dari satu format delimiter ke format lain.
     * Port dari: convertDate (line 2414) — format: yyyy-mm-dd → dd/mm/yyyy
     */
    public function convertDate(string $delimiter, string $ymd, string $delimiterHasil): string
    {
        if (empty($ymd) || $ymd === '0000-00-00' || $ymd === '0000/00/00') {
            return '';
        }
        $parts = explode($delimiter, $ymd);
        if (count($parts) !== 3) {
            return '';
        }
        return $parts[2] . $delimiterHasil . $parts[1] . $delimiterHasil . $parts[0];
    }

    /**
     * Konversi tanggal dari dd/mm/yyyy ke yyyy-mm-dd (format DB).
     * Port dari: convertDateToDB (line 2428)
     */
    public function convertDateToDb(string $delimiter, string $dmy, string $delimiterHasil): string
    {
        if (empty($dmy)) {
            return '';
        }
        $parts = explode($delimiter, $dmy);
        if (count($parts) !== 3) {
            return '';
        }
        return $parts[2] . $delimiterHasil . $parts[1] . $delimiterHasil . $parts[0];
    }

    /**
     * Reset semua state ke default.
     * Port dari: kosongkan (line 2318)
     */
    public function kosongkan(): void
    {
        $this->tanggalAwalPenangkapan = '';
        $this->tahunPenangkapan = 0;
        $this->bulanPenangkapan = 0;
        $this->tanggalPenangkapan = 0;

        $this->tahunAwalPenahanan = 0;
        $this->bulanAwalPenahanan = 0;
        $this->tanggalAwalPenahanan = 0;

        $this->tahunHukuman = 0;
        $this->bulanHukuman = 0;
        $this->hariHukuman = 0;

        $this->tahunPutusan = 0;
        $this->bulanPutusan = 0;
        $this->hariPutusan = 0;

        $this->tahunPotah = 0;
        $this->bulanPotah = 0;
        $this->hariPotah = 0;

        $this->hariDiluarUpt = 0;

        $this->tahunCabutPb = 0;
        $this->bulanCabutPb = 0;
        $this->hariCabutPb = 0;

        $this->tahunSubDenda = 0;
        $this->bulanSubDenda = 0;
        $this->hariSubDenda = 0;

        $this->tahunSubUp = 0;
        $this->bulanSubUp = 0;
        $this->hariSubUp = 0;

        $this->tahunSubRestitusi = 0;
        $this->bulanSubRestitusi = 0;
        $this->hariSubRestitusi = 0;

        $this->tahunLepas = 0;
        $this->bulanLepas = 0;
        $this->tglLepas = 0;
        $this->fullDateLepasDb = '';

        $this->tahunLepasSubsider = 0;
        $this->bulanLepasSubsider = 0;
        $this->tglLepasSubsider = 0;
        $this->tglEkspirasiSubsiderTanpaRemisi = '';

        $this->tahunLepasSubsiderUp = 0;
        $this->bulanLepasSubsiderUp = 0;
        $this->tglLepasSubsiderUp = 0;
        $this->tglEkspirasiSubsiderUpTanpaRemisi = '';

        $this->tahunLepasSubsiderRestitusi = 0;
        $this->bulanLepasSubsiderRestitusi = 0;
        $this->tglLepasSubsiderRestitusi = 0;
        $this->tglEkspirasiSubsiderRestitusiTanpaRemisi = '';

        $this->jmlRemisi = 0;
        $this->jmlRemisiDasawarsaLuarbiasa = 0;
        $this->remisi = [];
        $this->remisiDasawarsaLuarbiasa = [];
        $this->remisiRdBiiiRu2 = null;
        $this->remisiRdBiiiRu2FlagSubsider = null;
        $this->tglEkspirasiTanpaRemisi = '';

        $this->potah = [];
        $this->potahDetail = [];
        $this->jmlPotah = 0;

        $this->gJmlTglMulaiOverlap = 0;
        $this->gTglAkhirOverlap = '';
        $this->gJenisIdOverlap = '';
        $this->gJenisOverlap = '';
        $this->gTglAkhirOverlap2 = '';
        $this->gJenisIdOverlap2 = '';
        $this->gJenisOverlap2 = '';

        $this->tahunHuk = [];
        $this->bulanHuk = [];
        $this->hariHuk = [];
        $this->jmlHuk = 0;

        $this->isRumusBaru = false;
    }

    /**
     * Hitung selisih tanggal menggunakan metode Potah (360 hari/tahun).
     * Port dari: dateDifferentPotah (line 2060)
     */
    public function dateDifferentPotah(string $tglPutusan, string $tglAwalTahan): array
    {
        // Format: yyyy-mm-dd
        $d1 = date_create($tglAwalTahan);
        $d2 = date_create($tglPutusan);

        if (!$d1 || !$d2) {
            return ['years' => 0, 'months' => 0, 'days' => 0];
        }

        $diff = date_diff($d1, $d2);

        return [
            'years'  => $diff->y,
            'months' => $diff->m,
            'days'   => $diff->d,
        ];
    }

    /**
     * Getter: Potah (tahun, bulan, hari).
     * Port dari: get_potah (line 1561)
     */
    public function getPotah(): array
    {
        return [
            'tahun_potah' => $this->tahunPotah,
            'bulan_potah' => $this->bulanPotah,
            'hari_potah'  => $this->hariPotah,
        ];
    }

    // ==========================================
    // PLACEHOLDER METHODS (akan diimplementasi di Tahap 5.2-5.3)
    // ==========================================

    /**
     * Hitung ekspirasi awal (sebelum remisi).
     * Port dari: get_ekspirasi_semula (line 1972)
     */
    public function getEkspirasiSemula(string $format = 'Y-m-d'): ?string
    {
        $tipeEkspirasi = (!empty($this->tahunPutusan) && !empty($this->bulanPutusan) && !empty($this->hariPutusan)) ? 'napi' : 'tahanan';

        switch ($tipeEkspirasi) {
            case 'napi':
                $tahunHasil = $this->tahunPutusan;
                $bulanHasil = $this->bulanPutusan;
                $hariHasil = $this->hariPutusan;
                break;
            case 'tahanan':
            default:
                $tahunHasil = $this->tahunAwalPenahanan;
                $bulanHasil = $this->bulanAwalPenahanan;
                $hariHasil = $this->tanggalAwalPenahanan;
                break;
        }

        // Hukuman dari registrasi utama
        $tahunHasil += $this->tahunHukuman;
        $hariHasil += ($this->bulanHukuman * 30);
        $hariHasil += $this->hariHukuman;

        // Hukuman lain dari MAP
        for ($i = 0; $i < $this->jmlHuk; $i++) {
            $tahunHasil += $this->tahunHuk[$i];
            $hariHasil += ($this->bulanHuk[$i] * 30);
            $hariHasil += $this->hariHuk[$i];
        }

        // Sisa pidana pencabutan PB
        if (!empty($this->tahunCabutPb)) {
            $tahunHasil += $this->tahunCabutPb;
        }
        if (!empty($this->bulanCabutPb)) {
            $hariHasil += ($this->bulanCabutPb * 30);
        }
        if (!empty($this->hariCabutPb)) {
            $hariHasil += $this->hariCabutPb;
        }

        if ($bulanHasil === 0 && $hariHasil === 0 && $tahunHasil === 0) {
            return null;
        }

        $hasilAkhir = mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil);
        $awalTahan = mktime(0, 0, 0, $this->bulanAwalPenahanan, $this->tanggalAwalPenahanan, $this->tahunAwalPenahanan);

        if ($hasilAkhir < $awalTahan) {
            return null;
        }

        return date($format, mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));
    }

    /**
     * Hitung ekspirasi akhir (setelah remisi).
     * Port dari: get_ekspirasi (line 1566)
     */
    public function getEkspirasi(string $format = 'Y-m-d'): ?string
    {
        $tipeEkspirasi = (!empty($this->tahunPutusan) && !empty($this->bulanPutusan) && !empty($this->hariPutusan)) ? 'napi' : 'tahanan';
        
        $ekspirasiAwal = $this->getEkspirasiSemula('d/m/Y');
        
        if (!empty($ekspirasiAwal)) {
            $parts = explode('/', $ekspirasiAwal);
            $tahunHasil = (int) $parts[2];
            $bulanHasil = (int) $parts[1];
            $hariHasil = (int) $parts[0];
        } else {
            return null;
        }

        // Kalau potah
        if ($tipeEkspirasi === 'napi') {
            if ($this->isRumusBaru) {
                $hariPotah = 0;
                foreach ($this->potah as $p) {
                    $hariPotah += $p['LAMA_POTAH'];
                }
                $hariHasil -= $hariPotah;
                $this->tahunPotah = 0;
                $this->bulanPotah = 0;
                $this->hariPotah = $hariPotah;
            } else {
                $tahunHasil -= $this->tahunPotah;
                $hariHasil -= ($this->bulanPotah * 30);
                $hariHasil -= $this->hariPotah;
            }
        }

        // Hari diluar upt
        if (!empty($this->hariDiluarUpt)) {
            $hariHasil += $this->hariDiluarUpt;
        }

        // Set tanggal ekspirasi tanpa remisi
        $this->tglEkspirasiTanpaRemisi = date($format, mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));

        // Set default subsider tanpa remisi
        $this->tglEkspirasiSubsiderTanpaRemisi = $this->tglEkspirasiTanpaRemisi;
        $this->tglEkspirasiSubsiderUpTanpaRemisi = $this->tglEkspirasiTanpaRemisi;
        $this->tglEkspirasiSubsiderRestitusiTanpaRemisi = $this->tglEkspirasiTanpaRemisi;
        
        $tahunSubsiderTanpaRemisi = (int) date('Y', mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));
        $bulanSubsiderTanpaRemisi = (int) date('n', mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));
        $hariSubsiderTanpaRemisi = (int) date('j', mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));

        // Remisi
        for ($i = 0; $i < $this->jmlRemisi; $i++) {
            $tglPeruntukkan = '';
            if (!empty($this->remisi[$i]['tgl_peruntukkan'])) {
                $tglPeruntukkan = date('Y-m-d', strtotime($this->remisi[$i]['tgl_peruntukkan']));
            }

            // Tgl ekspirasi remisi sebelum pemberian remisi
            $tglEkspirasiRemisiSebelumnya = date('Y-m-d', mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));

            $params = [];
            if (!empty($this->remisi[$i]['params']['jenis_remisi'])) {
                $params['jenis_remisi'] = $this->remisi[$i]['params']['jenis_remisi'];
            }
            if (!empty($this->remisi[$i]['params']['pidana_subs'])) {
                $params['pidana_subs'] = $this->remisi[$i]['params']['pidana_subs'];
                $params['JENIS_REMISI'] = 'RZ2';
            }

            $hitungEkspirasiRemisi = $this->getEkspirasiRemisi(
                $tglEkspirasiRemisiSebelumnya,
                -$this->remisi[$i]['tahun_remisi'],
                -$this->remisi[$i]['bulan_remisi'],
                -$this->remisi[$i]['hari_remisi'],
                'Y-m-d',
                $tglPeruntukkan,
                true,
                $params
            );

            if (is_array($hitungEkspirasiRemisi)) {
                $this->remisi[$i]['tahun_remisi_sebenarnya'] = $hitungEkspirasiRemisi['tahun_remisi_sebenarnya'];
                $this->remisi[$i]['bulan_remisi_sebenarnya'] = $hitungEkspirasiRemisi['bulan_remisi_sebenarnya'];
                $this->remisi[$i]['hari_remisi_sebenarnya'] = $hitungEkspirasiRemisi['hari_remisi_sebenarnya'];

                if (isset($hitungEkspirasiRemisi['tahun_remisi_subsider'])) {
                    if (!empty($this->tahunSubDenda) || !empty($this->bulanSubDenda) || !empty($this->hariSubDenda)) {
                        $this->remisiRdBiiiRu2FlagSubsider = 'denda';
                    }

                    if (!empty($this->remisiRdBiiiRu2FlagSubsider)) {
                        $this->remisi[$i]['tahun_remisi_subsider'] = ($this->remisi[$i]['tahun_remisi_sebenarnya'] != $this->remisi[$i]['tahun_remisi']) ? ($this->remisi[$i]['tahun_remisi'] - $this->remisi[$i]['tahun_remisi_sebenarnya']) : $this->remisi[$i]['tahun_remisi'];
                        $this->remisi[$i]['bulan_remisi_subsider'] = ($this->remisi[$i]['bulan_remisi_sebenarnya'] != $this->remisi[$i]['bulan_remisi']) ? ($this->remisi[$i]['bulan_remisi'] - $this->remisi[$i]['bulan_remisi_sebenarnya']) : $this->remisi[$i]['bulan_remisi'];
                        $this->remisi[$i]['hari_remisi_subsider'] = ($this->remisi[$i]['hari_remisi_sebenarnya'] != $this->remisi[$i]['hari_remisi']) ? ($this->remisi[$i]['hari_remisi'] - $this->remisi[$i]['hari_remisi_sebenarnya']) : $this->remisi[$i]['hari_remisi'];
                        $this->remisiRdBiiiRu2 = $this->remisi[$i];
                    }
                }
                
                $tahunHasil -= $this->remisi[$i]['tahun_remisi_sebenarnya'];
                $hariHasil -= ($this->remisi[$i]['bulan_remisi_sebenarnya'] * 30);
                $hariHasil -= $this->remisi[$i]['hari_remisi_sebenarnya'];
            }
        }

        $stringHasil = date($format, mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));
        
        $this->tahunLepas = (int) date('Y', mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));
        $this->bulanLepas = (int) date('n', mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));
        $this->tglLepas = (int) date('j', mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));
        $this->fullDateLepasDb = date($format, mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil));

        // Subsider denda
        $tahunHasilSub = $this->tahunLepas;
        $bulanHasilSub = $this->bulanLepas;
        $hariHasilSub = $this->tglLepas;
        
        $tahunHasilSub += $this->tahunSubDenda;
        $hariHasilSub += ($this->bulanSubDenda * 30);
        $hariHasilSub += $this->hariSubDenda;

        $tahunSubsiderTanpaRemisi += $this->tahunSubDenda;
        $hariSubsiderTanpaRemisi += ($this->bulanSubDenda * 30);
        $hariSubsiderTanpaRemisi += $this->hariSubDenda;

        if (!empty($this->remisiRdBiiiRu2) && $this->remisiRdBiiiRu2FlagSubsider === 'denda') {
            $tglEkspirasiSubsiderBiii = date('Y-m-d', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
            $ekspirasiSubsiderBiiiSetelahRemisi = $this->getEkspirasiRemisi(
                $tglEkspirasiSubsiderBiii,
                -$this->remisiRdBiiiRu2['tahun_remisi_subsider'],
                -$this->remisiRdBiiiRu2['bulan_remisi_subsider'],
                -$this->remisiRdBiiiRu2['hari_remisi_subsider'],
                'Y-m-d',
                $this->remisiRdBiiiRu2['tgl_peruntukkan'],
                true,
                ['jenis_remisi' => $this->remisiRdBiiiRu2['jenis_remisi_grasi']]
            );

            if (is_array($ekspirasiSubsiderBiiiSetelahRemisi)) {
                $this->remisiRdBiiiRu2['tahun_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['tahun_remisi_sebenarnya'];
                $this->remisiRdBiiiRu2['bulan_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['bulan_remisi_sebenarnya'];
                $this->remisiRdBiiiRu2['hari_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['hari_remisi_sebenarnya'];

                $tahunHasilSub -= $this->remisiRdBiiiRu2['tahun_remisi_subsider'];
                $hariHasilSub -= ($this->remisiRdBiiiRu2['bulan_remisi_subsider'] * 30);
                $hariHasilSub -= $this->remisiRdBiiiRu2['hari_remisi_subsider'];

                $tahunSubsiderTanpaRemisi += $this->remisiRdBiiiRu2['tahun_remisi_subsider'];
                $hariSubsiderTanpaRemisi += ($this->remisiRdBiiiRu2['bulan_remisi_subsider'] * 30);
                $hariSubsiderTanpaRemisi += $this->remisiRdBiiiRu2['hari_remisi_subsider'];
            }
        }

        $this->tahunLepasSubsider = (int) date('Y', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
        $this->bulanLepasSubsider = (int) date('n', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
        $this->tglLepasSubsider = (int) date('j', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));

        $this->tglEkspirasiSubsiderTanpaRemisi = date('Y-m-d', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));
        
        $tahunSubsiderTanpaRemisi = (int) date('Y', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));
        $bulanSubsiderTanpaRemisi = (int) date('n', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));
        $hariSubsiderTanpaRemisi = (int) date('j', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));

        // Subsider UP
        $tahunHasilSub = $this->tahunLepasSubsider;
        $bulanHasilSub = $this->bulanLepasSubsider;
        $hariHasilSub = $this->tglLepasSubsider;

        $tahunHasilSub += $this->tahunSubUp;
        $hariHasilSub += ($this->bulanSubUp * 30);
        $hariHasilSub += $this->hariSubUp;

        $tahunSubsiderTanpaRemisi += $this->tahunSubUp;
        $hariSubsiderTanpaRemisi += ($this->bulanSubUp * 30);
        $hariSubsiderTanpaRemisi += $this->hariSubUp;

        if (!empty($this->remisiRdBiiiRu2) && $this->remisiRdBiiiRu2FlagSubsider === 'up') {
            $tglEkspirasiSubsiderBiii = date('Y-m-d', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
            $ekspirasiSubsiderBiiiSetelahRemisi = $this->getEkspirasiRemisi(
                $tglEkspirasiSubsiderBiii,
                -$this->remisiRdBiiiRu2['tahun_remisi_subsider'],
                -$this->remisiRdBiiiRu2['bulan_remisi_subsider'],
                -$this->remisiRdBiiiRu2['hari_remisi_subsider'],
                'Y-m-d',
                $this->remisiRdBiiiRu2['tgl_peruntukkan'],
                true
            );

            if (is_array($ekspirasiSubsiderBiiiSetelahRemisi)) {
                $this->remisiRdBiiiRu2['tahun_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['tahun_remisi_sebenarnya'];
                $this->remisiRdBiiiRu2['bulan_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['bulan_remisi_sebenarnya'];
                $this->remisiRdBiiiRu2['hari_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['hari_remisi_sebenarnya'];

                $tahunHasilSub -= $this->remisiRdBiiiRu2['tahun_remisi_subsider'];
                $hariHasilSub -= ($this->remisiRdBiiiRu2['bulan_remisi_subsider'] * 30);
                $hariHasilSub -= $this->remisiRdBiiiRu2['hari_remisi_subsider'];

                $tahunSubsiderTanpaRemisi += $this->remisiRdBiiiRu2['tahun_remisi_subsider'];
                $hariSubsiderTanpaRemisi += ($this->remisiRdBiiiRu2['bulan_remisi_subsider'] * 30);
                $hariSubsiderTanpaRemisi += $this->remisiRdBiiiRu2['hari_remisi_subsider'];
            }
        }

        $this->tahunLepasSubsiderUp = (int) date('Y', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
        $this->bulanLepasSubsiderUp = (int) date('n', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
        $this->tglLepasSubsiderUp = (int) date('j', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));

        $this->tglEkspirasiSubsiderUpTanpaRemisi = date('Y-m-d', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));
        
        $tahunSubsiderTanpaRemisi = (int) date('Y', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));
        $bulanSubsiderTanpaRemisi = (int) date('n', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));
        $hariSubsiderTanpaRemisi = (int) date('j', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));

        // Subsider Restitusi
        $tahunHasilSub = $this->tahunLepasSubsiderUp;
        $bulanHasilSub = $this->bulanLepasSubsiderUp;
        $hariHasilSub = $this->tglLepasSubsiderUp;

        $tahunHasilSub += $this->tahunSubRestitusi;
        $hariHasilSub += ($this->bulanSubRestitusi * 30);
        $hariHasilSub += $this->hariSubRestitusi;

        $tahunSubsiderTanpaRemisi += $this->tahunSubRestitusi;
        $hariSubsiderTanpaRemisi += ($this->bulanSubRestitusi * 30);
        $hariSubsiderTanpaRemisi += $this->hariSubRestitusi;

        if (!empty($this->remisiRdBiiiRu2) && $this->remisiRdBiiiRu2FlagSubsider === 'restitusi') {
            $tglEkspirasiSubsiderBiii = date('Y-m-d', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
            $ekspirasiSubsiderBiiiSetelahRemisi = $this->getEkspirasiRemisi(
                $tglEkspirasiSubsiderBiii,
                -$this->remisiRdBiiiRu2['tahun_remisi_subsider'],
                -$this->remisiRdBiiiRu2['bulan_remisi_subsider'],
                -$this->remisiRdBiiiRu2['hari_remisi_subsider'],
                'Y-m-d',
                $this->remisiRdBiiiRu2['tgl_peruntukkan'],
                true
            );

            if (is_array($ekspirasiSubsiderBiiiSetelahRemisi)) {
                $this->remisiRdBiiiRu2['tahun_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['tahun_remisi_sebenarnya'];
                $this->remisiRdBiiiRu2['bulan_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['bulan_remisi_sebenarnya'];
                $this->remisiRdBiiiRu2['hari_remisi_subsider'] = $ekspirasiSubsiderBiiiSetelahRemisi['hari_remisi_sebenarnya'];

                $tahunHasilSub -= $this->remisiRdBiiiRu2['tahun_remisi_subsider'];
                $hariHasilSub -= ($this->remisiRdBiiiRu2['bulan_remisi_subsider'] * 30);
                $hariHasilSub -= $this->remisiRdBiiiRu2['hari_remisi_subsider'];

                $tahunSubsiderTanpaRemisi += $this->remisiRdBiiiRu2['tahun_remisi_subsider'];
                $hariSubsiderTanpaRemisi += ($this->remisiRdBiiiRu2['bulan_remisi_subsider'] * 30);
                $hariSubsiderTanpaRemisi += $this->remisiRdBiiiRu2['hari_remisi_subsider'];
            }
        }

        $this->tahunLepasSubsiderRestitusi = (int) date('Y', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
        $this->bulanLepasSubsiderRestitusi = (int) date('n', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));
        $this->tglLepasSubsiderRestitusi = (int) date('j', mktime(0, 0, 0, $bulanHasilSub, $hariHasilSub, $tahunHasilSub));

        $this->tglEkspirasiSubsiderRestitusiTanpaRemisi = date('Y-m-d', mktime(0, 0, 0, $bulanSubsiderTanpaRemisi, $hariSubsiderTanpaRemisi, $tahunSubsiderTanpaRemisi));

        $hasilAkhir = mktime(0, 0, 0, $bulanHasil, $hariHasil, $tahunHasil);
        $awalTahan = mktime(0, 0, 0, $this->bulanAwalPenahanan, $this->tanggalAwalPenahanan, $this->tahunAwalPenahanan);

        if ($hasilAkhir < $awalTahan) {
            return null;
        }

        return $stringHasil;
    }

    /**
     * Hitung tanggal 1/3, 1/2, 2/3 masa pidana.
     * Port dari: hitung_tgl_seper (line 2747)
     *
     * @param string $mode          'sepertiga' | 'setengah' | 'duapertiga'
     * @param string $tanggalPenahanan  Format Y-m-d
     * @param array  $totalHukuman      ['tahun', 'bulan', 'hari']
     * @param array  $totalRemisi        ['bulan', 'hari'] (tidak ada tahun di legacy)
     * @param int    $hariDiluarUpt
     * @param bool   $isDetail          Jika true, return array ['tahun','bulan','hari','tgl_seper',...]
     * @param int    $hariPenahanan     Hari penahanan terputus (jika ada, skip hari diluar UPT)
     * @param bool   $hitungCabutPb
     *
     * @return string|array  Tanggal Y-m-d atau array jika $isDetail=true
     */
    public function hitungTglSeper(string $mode, string $tanggalPenahanan, array $totalHukuman, array $totalRemisi = [], int $hariDiluarUpt = 0, bool $isDetail = false, int $hariPenahanan = 0, bool $hitungCabutPb = false): string|array
    {
        $return = '';
        $tahunPerhitungan = 0;
        $bulanPerhitungan = 0;
        $hariPerhitungan = 0;

        // Parse tanggal penahanan Y-m-d → komponen
        $parts = explode('-', $tanggalPenahanan);
        if (count($parts) !== 3 || empty($parts[0])) {
            return $isDetail ? [] : '';
        }
        $tp = ['tahun' => (int) $parts[0], 'bulan' => (int) $parts[1], 'hari' => (int) $parts[2]];

        // Konversi hukuman ke hari (1 tahun = 360 hari, 1 bulan = 30 hari)
        $totalHari = ($totalHukuman['tahun'] * 360) + ($totalHukuman['bulan'] * 30) + ($totalHukuman['hari'] ?? 0);

        // Hitung 1/3, 1/2, atau 2/3 masa pidana
        switch ($mode) {
            case 'sepertiga':
                $hariPerhitungan = (int) floor($totalHari / 3);
                break;
            case 'setengah':
                $hariPerhitungan = (int) floor($totalHari / 2);
                break;
            case 'duapertiga':
                $hariPerhitungan = (int) floor($totalHari * 2 / 3);
                break;
        }

        // Kurangi remisi
        if (!empty($totalRemisi)) {
            $hariPerhitungan -= (($totalRemisi['bulan'] ?? 0) * 30) + ($totalRemisi['hari'] ?? 0);
        }

        // Tambah sisa pidana pencabutan PB
        if ($hitungCabutPb && (!empty($this->tahunCabutPb) || !empty($this->bulanCabutPb) || !empty($this->hariCabutPb))) {
            $hariPerhitungan += ($this->tahunCabutPb * 360) + ($this->bulanCabutPb * 30) + $this->hariCabutPb;
        }

        if ($hariPerhitungan < 0) {
            $return = $tanggalPenahanan;
            $hariPerhitungan = 0;
        } else {
            // Konversi balik ke tahun, bulan, hari
            $tahunPerhitungan = (int) floor($hariPerhitungan / 360);
            $hariPerhitungan -= $tahunPerhitungan * 360;
            $bulanPerhitungan = (int) floor($hariPerhitungan / 30);
            $hariPerhitungan -= $bulanPerhitungan * 30;

            // Tanggal awal penahanan + durasi 1/3-2/3 + hari di luar UPT
            $tp['tahun'] += $tahunPerhitungan;
            $tp['hari'] += ($bulanPerhitungan * 30) + $hariPerhitungan;

            // Jika penahanan terputus, jangan tambah hari_diluar_upt
            if (empty($hariPenahanan)) {
                $tp['hari'] += $hariDiluarUpt;
            }

            $return = date('Y-m-d', mktime(0, 0, 0, $tp['bulan'], $tp['hari'], $tp['tahun']));
        }

        if ($isDetail) {
            $parsedReturn = [];
            if (!empty($return)) {
                $rParts = explode('-', $return);
                $parsedReturn = [
                    'tahun' => (int) ($rParts[0] ?? 0),
                    'bulan' => (int) ($rParts[1] ?? 0),
                    'hari'  => (int) ($rParts[2] ?? 0),
                ];
            }
            $parsedReturn['tgl_seper']    = $return;
            $parsedReturn['tahun_seper']  = $tahunPerhitungan;
            $parsedReturn['bulan_seper']  = $bulanPerhitungan;
            $parsedReturn['hari_seper']   = $hariPerhitungan;
            return $parsedReturn;
        }

        return $return;
    }

    /**
     * Hitung ekspirasi remisi (pengurangan per-remisi).
     * Port dari: get_ekspirasi_remisi (line 1855)
     */
    public function getEkspirasiRemisi(string $tanggalEkspirasi = '', int $tahun = 0, int $bulan = 0, int $hari = 0, string $format = 'Y-m-d', string $tglPeruntukkan = '', bool $returnFull = false, array $params = []): array|string
    {
        if (trim($tanggalEkspirasi) === '') {
            return '';
        }

        $parts = explode('-', $tanggalEkspirasi);
        $tahunHasil = (int) $parts[0];
        $bulanHasil = (int) $parts[1];
        $hariHasil = (int) $parts[2];

        $tahunRemisi = $tahunHasil + $tahun;
        $hariRemisi = $hariHasil + ($bulan * 30);
        $hariRemisi += $hari;
        
        $ekspirasiRemisiTs = mktime(0, 0, 0, $bulanHasil, $hariRemisi, $tahunRemisi);

        if ($returnFull) {
            $result = [];
            if (isset($params['JENIS_REMISI']) && $params['JENIS_REMISI'] === 'RZ2') {
                $result['tahun_remisi_sebenarnya'] = 0;
                $result['bulan_remisi_sebenarnya'] = 0;
                $result['hari_remisi_sebenarnya'] = 0;

                $result['tahun_remisi_subsider'] = abs($tahun);
                $result['bulan_remisi_subsider'] = abs($bulan);
                $result['hari_remisi_subsider'] = abs($hari);
            } else {
                $result['tahun_remisi_sebenarnya'] = abs($tahun);
                $result['bulan_remisi_sebenarnya'] = abs($bulan);
                $result['hari_remisi_sebenarnya'] = abs($hari);
            }

            if (!empty($tglPeruntukkan)) {
                $remParts = explode('-', $tglPeruntukkan);
                $peruntukkanRemisiTs = mktime(0, 0, 0, (int)$remParts[1], (int)$remParts[2], (int)$remParts[0]);

                $result['peruntukkan_remisi_dt'] = $peruntukkanRemisiTs;

                if (($tahun < 0 || $bulan < 0 || $hari < 0) && $peruntukkanRemisiTs > $ekspirasiRemisiTs) {
                    
                    if (!(isset($params['JENIS_REMISI']) && $params['JENIS_REMISI'] === 'RZ2')) {
                        // dateDiffFull equivalent
                        $diff = $this->dateDiffFull(date('Y-m-d', $peruntukkanRemisiTs), $tanggalEkspirasi);
                        $result['tahun_remisi_sebenarnya'] = (int) $diff['years'];
                        $result['bulan_remisi_sebenarnya'] = (int) $diff['months'];
                        $result['hari_remisi_sebenarnya'] = (int) $diff['days'];
                    }

                    $ekspirasiRemisiTs = $peruntukkanRemisiTs;

                    $tglBulanPeruntukkan = date('dm', $peruntukkanRemisiTs);

                    if ($tglBulanPeruntukkan === '1708' && (abs($bulan) === 0 && abs($hari) > 0) && (isset($params['jenis_remisi']) && $params['jenis_remisi'] === 'RZ2')) {
                        if (isset($params['pidana_subs']) && $params['pidana_subs'] == 1) {
                            $result['tahun_remisi_subsider'] = abs($tahun);
                            $result['bulan_remisi_subsider'] = abs($bulan);
                            $result['hari_remisi_subsider'] = abs($hari);
                        }
                    }
                }
            }
            
            $result['ekspirasi_remisi_dt'] = $ekspirasiRemisiTs;
            $result['tgl_ekspirasi_remisi'] = date($format, $ekspirasiRemisiTs);

            return $result;
        }

        return date($format, $ekspirasiRemisiTs);
    }
    
    /**
     * Hitung selisih lengkap (tahun, bulan, hari).
     * Port dari func.php::datedifferent2
     */
    public function dateDiffFull(string $tgl1, string $tgl2): array
    {
        $ts1 = strtotime($tgl1);
        $ts2 = strtotime($tgl2);

        $diffSecs = (int) abs($ts1 - $ts2);
        $daysTotal = (int) round($diffSecs / 86400);

        $haries = $this->hitungHariBulanTahun($daysTotal);

        return [
            'years' => $haries['tahun'],
            'months' => $haries['bulan'],
            'days' => $haries['hari']
        ];
    }

    /**
     * Recalculate penahanan — overlapping detection rumus baru.
     * Port dari: recalculate_penahanan (line 710)
     *
     * Dijalankan SEBELUM getEkspirasi() ketika is_rumus_baru = true.
     * Mengisi $this->potah_detail dengan rentang-rentang non-overlapping,
     * lalu menghitung ulang LAMA_POTAH untuk setiap elemen $this->potah.
     */
    public function recalculatePenahanan(): void
    {
        $tglPutusan = sprintf('%04d%02d%02d', $this->tahunPutusan, $this->bulanPutusan, $this->hariPutusan);
        $tglPutusanBaru = date('Ymd', strtotime($tglPutusan) - 86400);

        $tahananLuarOverlap = [];

        for ($i1 = 0; $i1 < $this->jmlPotah; $i1++) {
            if (empty($this->potah[$i1]['TGL_MULAI']) || empty($this->potah[$i1]['TGL_SELESAI'])) {
                continue;
            }

            // Clamp ke sebelum putusan
            if ($this->potah[$i1]['TGL_MULAI_REAL'] >= $tglPutusan) {
                $this->potah[$i1]['TGL_MULAI_REAL'] = $tglPutusanBaru;
            }
            if ($this->potah[$i1]['TGL_SELESAI_REAL'] >= $tglPutusan) {
                $this->potah[$i1]['TGL_SELESAI_REAL'] = $tglPutusanBaru;
            }

            if ($this->potah[$i1]['TGL_MULAI_REAL'] > $this->potah[$i1]['TGL_SELESAI_REAL']) {
                continue;
            }

            // Pembantaran/Penangguhan potah = 0
            if (in_array($this->potah[$i1]['JENIS_ID'], ['STL3', 'STL4'])) {
                $this->potah[$i1]['LAMA_POTAH'] = 0;
            }

            $isNormal = true;

            for ($i2 = 0; $i2 < $this->jmlPotah; $i2++) {
                if ($this->potah[$i2]['TGL_MULAI_REAL'] >= $tglPutusan) {
                    $this->potah[$i2]['TGL_MULAI_REAL'] = $tglPutusanBaru;
                }
                if ($this->potah[$i2]['TGL_SELESAI_REAL'] >= $tglPutusan) {
                    $this->potah[$i2]['TGL_SELESAI_REAL'] = $tglPutusanBaru;
                }

                if ($i2 <= $i1) {
                    continue;
                }
                if ($this->potah[$i2]['TGL_MULAI_REAL'] > $this->potah[$i2]['TGL_SELESAI_REAL']) {
                    continue;
                }

                $m1 = $this->potah[$i1]['TGL_MULAI_REAL'];
                $s1 = $this->potah[$i1]['TGL_SELESAI_REAL'];
                $m2 = $this->potah[$i2]['TGL_MULAI_REAL'];
                $s2 = $this->potah[$i2]['TGL_SELESAI_REAL'];

                if ($m1 < $m2) {
                    if ($s1 < $m2) {
                        // no overlap — skip
                    } elseif ($s1 == $m2) {
                        // potah1 selesai = potah2 mulai → kurangi 1 hari
                        $tglBaruS = date('Ymd', strtotime($m2) - 86400);
                        if ($tglBaruS >= $tglPutusan) {
                            $tglBaruS = $tglPutusanBaru;
                        }
                        if (!in_array($this->potah[$i1]['JENIS_ID'], ['STL3', 'STL4'])) {
                            $this->potahDetail[] = [
                                'TGL_MULAI' => $m1, 'TGL_SELESAI' => $tglBaruS,
                                'JENIS_ID' => $this->potah[$i1]['JENIS_ID'],
                                'JENIS' => $this->potah[$i1]['JENIS'],
                                'PRIORITAS' => $this->potah[$i1]['PRIORITAS'],
                                'BARIS' => $i1,
                            ];
                        }
                        if (in_array($this->potah[$i2]['JENIS_ID'], ['STL3', 'STL4'])) {
                            $tahananLuarOverlap[] = ['TGL_MULAI' => $m2, 'TGL_SELESAI' => $m2,
                                'JENIS_ID' => $this->potah[$i2]['JENIS_ID'], 'JENIS' => $this->potah[$i2]['JENIS'],
                                'PRIORITAS' => $this->potah[$i2]['PRIORITAS'], 'BARIS' => $i2];
                        }
                        $isNormal = false;
                        break;
                    } elseif ($s1 > $m2) {
                        // Overlap partial
                        $tglBaruS = date('Ymd', strtotime($m2) - 86400);
                        if ($tglBaruS >= $tglPutusan) {
                            $tglBaruS = $tglPutusanBaru;
                        }
                        $this->potahDetail[] = [
                            'TGL_MULAI' => $m1, 'TGL_SELESAI' => $tglBaruS,
                            'JENIS_ID' => $this->potah[$i1]['JENIS_ID'],
                            'JENIS' => $this->potah[$i1]['JENIS'],
                            'PRIORITAS' => $this->potah[$i1]['PRIORITAS'],
                            'BARIS' => $i1,
                        ];
                        if (in_array($this->potah[$i2]['JENIS_ID'], ['STL3', 'STL4'])) {
                            $tahananLuarOverlap[] = ['TGL_MULAI' => $m2, 'TGL_SELESAI' => $m2,
                                'JENIS_ID' => $this->potah[$i2]['JENIS_ID'], 'JENIS' => $this->potah[$i2]['JENIS'],
                                'PRIORITAS' => $this->potah[$i2]['PRIORITAS'], 'BARIS' => $i2];
                        }
                        $isNormal = false;
                        break;
                    }
                } elseif ($m1 == $m2) {
                    if ($s1 < $s2) {
                        $this->potah[$i1]['LAMA_POTAH'] = 0;
                        $isNormal = false;
                        break;
                    } elseif ($s1 == $s2) {
                        $this->potah[$i1]['LAMA_POTAH'] = 0;
                        $isNormal = false;
                        break;
                    } elseif ($s1 > $s2) {
                        // Overlap partial — potah1 lebih panjang
                        $tglBaruS = date('Ymd', strtotime($m2) - 86400);
                        if ($tglBaruS >= $tglPutusan) {
                            $tglBaruS = $tglPutusanBaru;
                        }
                        if (!in_array($this->potah[$i1]['JENIS_ID'], ['STL3', 'STL4'])) {
                            $this->potahDetail[] = [
                                'TGL_MULAI' => $m1, 'TGL_SELESAI' => $tglBaruS,
                                'JENIS_ID' => $this->potah[$i1]['JENIS_ID'],
                                'JENIS' => $this->potah[$i1]['JENIS'],
                                'PRIORITAS' => $this->potah[$i1]['PRIORITAS'],
                                'BARIS' => $i1,
                            ];
                        }
                        if (in_array($this->potah[$i2]['JENIS_ID'], ['STL3', 'STL4'])) {
                            $tahananLuarOverlap[] = ['TGL_MULAI' => $m2, 'TGL_SELESAI' => $m2,
                                'JENIS_ID' => $this->potah[$i2]['JENIS_ID'], 'JENIS' => $this->potah[$i2]['JENIS'],
                                'PRIORITAS' => $this->potah[$i2]['PRIORITAS'], 'BARIS' => $i2];
                        }
                        $isNormal = false;
                        break;
                    }
                }
            }

            if ($isNormal && !in_array($this->potah[$i1]['JENIS_ID'], ['STL3', 'STL4'])) {
                $this->potahDetail[] = [
                    'TGL_MULAI' => $this->potah[$i1]['TGL_MULAI_REAL'],
                    'TGL_SELESAI' => $this->potah[$i1]['TGL_SELESAI_REAL'],
                    'JENIS_ID' => $this->potah[$i1]['JENIS_ID'],
                    'JENIS' => $this->potah[$i1]['JENIS'],
                    'PRIORITAS' => $this->potah[$i1]['PRIORITAS'],
                    'BARIS' => $i1,
                ];
            }
        }

        // Tambah pembantaran/penangguhan yang overlap
        foreach ($tahananLuarOverlap as $tl) {
            $this->potahDetail[] = $tl;
        }

        // Rekursif resolve sisa overlap di potahDetail
        if (!empty($this->potahDetail)) {
            $this->recursivePenahanan();
        }

        // Hitung ulang LAMA_POTAH berdasarkan potahDetail
        if (!empty($this->potahDetail)) {
            foreach ($this->potah as $idx => $potah) {
                $lamaPotah = 0;
                foreach ($this->potahDetail as $idxD => $detail) {
                    if ($idx === $detail['BARIS']) {
                        $result = $this->hitungPenahanan(
                            $detail['JENIS_ID'],
                            $detail['JENIS'],
                            $detail['TGL_MULAI'],
                            $detail['TGL_SELESAI'],
                            true
                        );
                        $lamaPotah += $result['LAMA_POTAH'];
                        $this->potahDetail[$idxD]['LAMA_POTAH'] = $result['LAMA_POTAH'];
                    }
                }
                $this->potah[$idx]['LAMA_POTAH'] = $lamaPotah;
            }
        }
    }

    /**
     * Recursive overlap resolution untuk potahDetail.
     * Port dari: recursive_penahanan (line 520)
     *
     * Mengurutkan potahDetail by TGL_MULAI, lalu secara rekursif menyelesaikan
     * semua overlap hingga bersih (tidak ada 2 rentang yang bertabrakan).
     */
    public function recursivePenahanan(): void
    {
        if (empty($this->potahDetail)) {
            return;
        }

        // Sort by TGL_MULAI ASC, PRIORITAS ASC
        $sort = [];
        foreach ($this->potahDetail as $idx => $val) {
            $sort['TGL_MULAI'][$idx] = $val['TGL_MULAI'];
            $sort['PRIORITAS'][$idx] = $val['PRIORITAS'];
        }
        array_multisort($sort['TGL_MULAI'], SORT_ASC, $sort['PRIORITAS'], $this->potahDetail);

        $recursive = false;
        $total = count($this->potahDetail);

        for ($i1 = 0; $i1 < $total; $i1++) {
            if ($this->potahDetail[$i1]['TGL_MULAI'] > $this->potahDetail[$i1]['TGL_SELESAI']) {
                continue;
            }

            for ($i2 = 0; $i2 < $total; $i2++) {
                if ($i2 <= $i1) {
                    continue;
                }
                if (!isset($this->potahDetail[$i2])) {
                    continue;
                }
                if ($this->potahDetail[$i2]['TGL_MULAI'] > $this->potahDetail[$i2]['TGL_SELESAI']) {
                    continue;
                }

                $m1 = $this->potahDetail[$i1]['TGL_MULAI'];
                $s1 = $this->potahDetail[$i1]['TGL_SELESAI'];
                $m2 = $this->potahDetail[$i2]['TGL_MULAI'];
                $s2 = $this->potahDetail[$i2]['TGL_SELESAI'];
                $b1 = $this->potahDetail[$i1]['BARIS'];
                $b2 = $this->potahDetail[$i2]['BARIS'];

                if ($m1 < $m2) {
                    if ($s1 < $m2) {
                        // no overlap
                    } elseif ($s1 == $m2) {
                        if ($b1 <= $b2) {
                            $this->potahDetail[$i1]['TGL_SELESAI'] = date('Ymd', strtotime($m2) - 86400);
                        } else {
                            $this->potahDetail[$i2]['TGL_MULAI'] = date('Ymd', strtotime($m2) + 86400);
                        }
                        $recursive = true;
                        break;
                    } elseif ($s1 > $m2) {
                        if ($b1 <= $b2) {
                            $this->potahDetail[$i1]['TGL_SELESAI'] = date('Ymd', strtotime($m2) - 86400);
                        } else {
                            $this->potahDetail[$i2]['TGL_MULAI'] = date('Ymd', strtotime($m2) + 86400);
                        }
                        // Sub-overlap tanggal selesai
                        if ($s1 >= $s2) {
                            if ($b1 <= $b2) {
                                $this->potahDetail[] = [
                                    'TGL_MULAI' => $s2, 'TGL_SELESAI' => $s1,
                                    'JENIS_ID' => $this->potahDetail[$i1]['JENIS_ID'],
                                    'JENIS' => $this->potahDetail[$i1]['JENIS'],
                                    'PRIORITAS' => $this->potahDetail[$i1]['PRIORITAS'],
                                    'BARIS' => $b1,
                                ];
                            } else {
                                $this->potahDetail[] = [
                                    'TGL_MULAI' => $s1, 'TGL_SELESAI' => $s2,
                                    'JENIS_ID' => $this->potahDetail[$i2]['JENIS_ID'],
                                    'JENIS' => $this->potahDetail[$i2]['JENIS'],
                                    'PRIORITAS' => $this->potahDetail[$i2]['PRIORITAS'],
                                    'BARIS' => $b2,
                                ];
                            }
                        }
                        $recursive = true;
                        break;
                    }
                } elseif ($m1 == $m2) {
                    if ($s1 < $s2) {
                        if ($b1 <= $b2) {
                            unset($this->potahDetail[$i1]);
                        } else {
                            unset($this->potahDetail[$i2]);
                        }
                        $recursive = true;
                        break;
                    } elseif ($s1 == $s2) {
                        if ($b1 <= $b2) {
                            unset($this->potahDetail[$i1]);
                        } else {
                            unset($this->potahDetail[$i2]);
                        }
                        $recursive = true;
                        break;
                    } elseif ($s1 > $s2) {
                        if ($b1 <= $b2) {
                            $this->potahDetail[$i1]['TGL_SELESAI'] = date('Ymd', strtotime($m2) - 86400);
                        } else {
                            $this->potahDetail[$i2]['TGL_MULAI'] = date('Ymd', strtotime($m2) + 86400);
                        }
                        if ($s1 >= $s2) {
                            if ($b1 <= $b2) {
                                $this->potahDetail[] = [
                                    'TGL_MULAI' => $s2, 'TGL_SELESAI' => $s1,
                                    'JENIS_ID' => $this->potahDetail[$i1]['JENIS_ID'],
                                    'JENIS' => $this->potahDetail[$i1]['JENIS'],
                                    'PRIORITAS' => $this->potahDetail[$i1]['PRIORITAS'],
                                    'BARIS' => $b1,
                                ];
                            } else {
                                $this->potahDetail[] = [
                                    'TGL_MULAI' => $s1, 'TGL_SELESAI' => $s2,
                                    'JENIS_ID' => $this->potahDetail[$i2]['JENIS_ID'],
                                    'JENIS' => $this->potahDetail[$i2]['JENIS'],
                                    'PRIORITAS' => $this->potahDetail[$i2]['PRIORITAS'],
                                    'BARIS' => $b2,
                                ];
                            }
                        }
                        $recursive = false; // sesuai legacy line 682
                        break;
                    }
                }
            }

            if ($recursive) {
                break;
            }
        }

        if ($recursive) {
            $this->potahDetail = array_values($this->potahDetail);
            $this->recursivePenahanan();
        }
    }

    /**
     * Load penahanan dari DB.
     * Port dari: get_penahanan_array (line 1224)
     */
    public function getPenahananArray(?string $idPerkara = null, array $dataPenahanan = []): void
    {
        if (empty($idPerkara)) {
            return;
        }

        $penahananList = $this->repository->getPenahananByPerkara($idPerkara);
        foreach ($penahananList as $p) {
            $this->setPenahanan(
                $p['JENIS_ID'],
                $p['JENIS'],
                $p['TGL_MULAI'] ? $this->convertDate('-', $p['TGL_MULAI'], '/') : '',
                $p['TGL_SELESAI'] ? $this->convertDate('-', $p['TGL_SELESAI'], '/') : '',
                false,
                true
            );
        }
    }

    /**
     * Load remisi dari DB.
     * Port dari: get_remisi_array (line 1426)
     */
    public function getRemisiArray(?string $idPerkara = null): void
    {
        if (empty($idPerkara)) {
            return;
        }

        $remisiList = $this->repository->getRemisiByPerkara($idPerkara);
        foreach ($remisiList as $r) {
            $this->setRemisi(
                (int) $r['TAHUN_REMISI'],
                (int) $r['BULAN_REMISI'],
                (int) $r['HARI_REMISI'],
                $r['TGL_PERUNTUKKAN'],
                $r['ID_JENIS_REMISI'] ?? 'REMISI',
                true,
                ['jenis_remisi' => $r['ID_JENIS_REMISI']]
            );
        }

        $grasiList = $this->repository->getGrasiByPerkara($idPerkara);
        foreach ($grasiList as $g) {
            $this->setRemisi(
                (int) $g['TAHUN_GRASI'],
                (int) $g['BULAN_GRASI'],
                (int) $g['HARI_GRASI'],
                $g['TGL_PERUNTUKKAN'],
                'GRASI',
                true
            );
        }
    }

    /**
     * Hitung ekspirasi + subsider denda.
     * Port dari: get_ekspirasi_plus_subsider (line 2029)
     */
    public function getEkspirasiPlusSubsider(string $format = 'Y-m-d'): string
    {
        if (empty($this->bulanLepasSubsider) && empty($this->tglLepasSubsider) && empty($this->tahunLepasSubsider)) {
            return '';
        }

        return date($format, mktime(0, 0, 0, $this->bulanLepasSubsider, $this->tglLepasSubsider, $this->tahunLepasSubsider));
    }

    /**
     * Hitung ekspirasi + subsider denda + uang pengganti.
     * Port dari: get_ekspirasi_plus_subsider_plus_up (line 2040)
     */
    public function getEkspirasiPlusSubsiderPlusUp(string $format = 'Y-m-d'): string
    {
        if (empty($this->bulanLepasSubsiderUp) && empty($this->tglLepasSubsiderUp) && empty($this->tahunLepasSubsiderUp)) {
            return '';
        }

        return date($format, mktime(0, 0, 0, $this->bulanLepasSubsiderUp, $this->tglLepasSubsiderUp, $this->tahunLepasSubsiderUp));
    }

    /**
     * Hitung ekspirasi + subsider denda + uang pengganti + restitusi.
     * Port dari: get_ekspirasi_plus_subsider_plus_up_plus_restitusi (line 2050)
     */
    public function getEkspirasiPlusSubsiderPlusUpPlusRestitusi(string $format = 'Y-m-d'): string
    {
        if (empty($this->bulanLepasSubsiderRestitusi) && empty($this->tglLepasSubsiderRestitusi) && empty($this->tahunLepasSubsiderRestitusi)) {
            return '';
        }

        return date($format, mktime(0, 0, 0, $this->bulanLepasSubsiderRestitusi, $this->tglLepasSubsiderRestitusi, $this->tahunLepasSubsiderRestitusi));
    }

    /**
     * Update ekspirasi langsung ke tabel perkara.
     * Port dari: set_tanggal_ekspirasi_langsung (line 2621)
     */
    public function setTanggalEkspirasiLangsung(string $idPerkara, string $noRegGol, string $tglLepas, bool $direct = false, string $berita = ''): void
    {
        // TODO Tahap 5.4
    }

    /**
     * Update field-field ekspirasi parsial pada tabel perkara.
     * Port dari: updateEkspirasiPart (line 2636)
     */
    public function updateEkspirasiPart(?string $idp = null, array $hasilHitung = [], string $berita = ''): void
    {
        // TODO Tahap 5.4
    }
}
