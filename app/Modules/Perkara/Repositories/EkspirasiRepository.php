<?php

namespace App\Modules\Perkara\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * EkspirasiRepository
 *
 * Mengelola semua query database yang diperlukan oleh EkspirasiCalculatorService.
 * Dipisahkan dari logika perhitungan agar kalkulator bisa di-test secara independen.
 *
 * Port dari query-query yang tersebar di dalam legacy Func_ekspirasi.php CI2.
 */
final class EkspirasiRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Ambil data perkara utama beserta field-field yang diperlukan kalkulator.
     */
    public function getPerkaraById(string $idPerkara): ?array
    {
        $row = $this->db->table('perkara')
            ->where('ID_PERKARA', $idPerkara)
            ->where('IS_DELETE', '0')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Cek apakah perkara ini adalah MAP (multi-perkara).
     * Jika ya, return ID_PERKARA_PARENT.
     */
    public function getMapParent(string $idPerkara): ?string
    {
        $row = $this->db->table('perkara')
            ->select('ID_PERKARA_PARENT')
            ->where('ID_PERKARA', $idPerkara)
            ->where('ID_SUB_STATUS', 'SS11')
            ->where('IS_DELETE', '0')
            ->get()
            ->getRowArray();

        return $row ? ($row['ID_PERKARA_PARENT'] ?: null) : null;
    }

    /**
     * Ambil data perkara MAP (child perkara yang di-join).
     */
    public function getMapChildren(string $idPerkaraParent): array
    {
        return $this->db->table('perkara')
            ->select('perkara.ID_PERKARA, TAHUN_HUKUMAN, BULAN_HUKUMAN, HARI_HUKUMAN,
                      THN_SUB_UP_AKHIR, BLN_SUB_UP_AKHIR, HR_SUB_UP_AKHIR,
                      THN_SUB_DENDA_AKHIR, BLN_SUB_DENDA_AKHIR, HR_SUB_DENDA_AKHIR,
                      TERMINOLOGI,
                      perkara.TAHUN_CABUTPB, perkara.BULAN_CABUTPB, perkara.HARI_CABUTPB')
            ->join('kejahatan', 'kejahatan.ID_PERKARA = perkara.ID_PERKARA AND kejahatan.NOREGGOL = perkara.NMR_REG_GOL AND kejahatan.IS_DELETED = 0', 'left')
            ->join('terminologi', 'kejahatan.ID_TERMINOLOGI = terminologi.ID_TERMINOLOGI', 'left')
            ->where('ID_PERKARA_PARENT', $idPerkaraParent)
            ->where('ID_SUB_STATUS', 'SS11')
            ->where('perkara.IS_DELETE', '0')
            ->get()
            ->getResultArray();
    }

    /**
     * Ambil data riwayat penahanan (penahanan, perpanjangan, tahanan luar)
     * dari tabel penahanan dan penahanan_lanjutan.
     */
    public function getPenahananByPerkara(string $idPerkara): array
    {
        // Penahanan utama
        $penahanan = $this->db->table('penahanan')
            ->select('ID_JENIS_PENAHANAN AS JENIS_ID, jenis_penahanan.DESKRIPSI AS JENIS, TGL_MULAI, TGL_SELESAI')
            ->join('jenis_penahanan', 'jenis_penahanan.ID_JENIS_PENAHANAN = penahanan.ID_JENIS_PENAHANAN', 'left')
            ->where('penahanan.ID_PERKARA', $idPerkara)
            ->where('penahanan.IS_DELETED', '0')
            ->get()
            ->getResultArray();

        // Penahanan lanjutan
        $lanjutan = $this->db->table('penahanan_lanjutan')
            ->select('ID_JENIS_PENAHANAN AS JENIS_ID, jenis_penahanan.DESKRIPSI AS JENIS, TGL_MULAI, TGL_SELESAI')
            ->join('jenis_penahanan', 'jenis_penahanan.ID_JENIS_PENAHANAN = penahanan_lanjutan.ID_JENIS_PENAHANAN', 'left')
            ->where('penahanan_lanjutan.ID_PERKARA', $idPerkara)
            ->where('penahanan_lanjutan.IS_DELETED', '0')
            ->get()
            ->getResultArray();

        return array_merge($penahanan, $lanjutan);
    }

    /**
     * Ambil data remisi yang sudah disetujui (STATUS_RMS = 2).
     */
    public function getRemisiByPerkara(string $idPerkara): array
    {
        return $this->db->table('remisi')
            ->select('remisi.ID_REMISI, remisi.ID_JENIS_REMISI, remisi.TGL_PERUNTUKKAN,
                      remisi.TAHUN_REMISI, remisi.BULAN_REMISI, remisi.HARI_REMISI,
                      jenis_remisi.DESKRIPSI AS JENIS_DESKRIPSI')
            ->join('jenis_remisi', 'jenis_remisi.ID_JENIS_REMISI = remisi.ID_JENIS_REMISI', 'left')
            ->where('remisi.ID_PERKARA', $idPerkara)
            ->where('remisi.STATUS_RMS', '2')
            ->where('remisi.IS_DELETED', '0')
            ->orderBy('remisi.TGL_PERUNTUKKAN', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Ambil data grasi yang sudah disetujui.
     */
    public function getGrasiByPerkara(string $idPerkara): array
    {
        return $this->db->table('grasi')
            ->select('grasi.ID_GRASI, grasi.TGL_PERUNTUKKAN,
                      grasi.TAHUN_GRASI, grasi.BULAN_GRASI, grasi.HARI_GRASI')
            ->where('grasi.ID_PERKARA', $idPerkara)
            ->where('grasi.STATUS_GRASI', '2')
            ->where('grasi.IS_DELETED', '0')
            ->orderBy('grasi.TGL_PERUNTUKKAN', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Ambil subsider denda.
     * Port dari Func_ekspirasi::getSubDenda()
     */
    public function getSubDenda(string $idPerkara): array
    {
        $row = $this->db->table('denda')
            ->select('IFNULL(SUM(THN_SUB_DENDA),0) AS THN_SUB_DENDA,
                      IFNULL(SUM(BLN_SUB_DENDA),0) AS BLN_SUB_DENDA,
                      IFNULL(SUM(HR_SUB_DENDA),0) AS HR_SUB_DENDA')
            ->where('ID_PERKARA', $idPerkara)
            ->where('IS_DELETED', '0')
            ->get()
            ->getRowArray();

        return $row ?: ['THN_SUB_DENDA' => 0, 'BLN_SUB_DENDA' => 0, 'HR_SUB_DENDA' => 0];
    }

    /**
     * Ambil subsider uang pengganti.
     * Port dari Func_ekspirasi::getSubUangPengganti()
     */
    public function getSubUangPengganti(string $idPerkara, string $extraWhere = ''): array
    {
        $builder = $this->db->table('uang_pengganti')
            ->select('IFNULL(SUM(THN_SUB_UP),0) AS THN_SUB_UP,
                      IFNULL(SUM(BLN_SUB_UP),0) AS BLN_SUB_UP,
                      IFNULL(SUM(HR_SUB_UP),0) AS HR_SUB_UP')
            ->where('ID_PERKARA', $idPerkara)
            ->where('IS_DELETED', '0');

        if (!empty($extraWhere)) {
            $builder->where($extraWhere);
        }

        $row = $builder->get()->getRowArray();

        return $row ?: ['THN_SUB_UP' => 0, 'BLN_SUB_UP' => 0, 'HR_SUB_UP' => 0];
    }

    /**
     * Ambil subsider restitusi.
     * Port dari Func_ekspirasi::getSubRestitusi()
     */
    public function getSubRestitusi(string $idPerkara): array
    {
        $row = $this->db->table('restitusi')
            ->select('IFNULL(SUM(THN_SUB_RESTITUSI),0) AS THN_SUB_RESTITUSI,
                      IFNULL(SUM(BLN_SUB_RESTITUSI),0) AS BLN_SUB_RESTITUSI,
                      IFNULL(SUM(HR_SUB_RESTITUSI),0) AS HR_SUB_RESTITUSI')
            ->where('ID_PERKARA', $idPerkara)
            ->where('IS_DELETED', '0')
            ->get()
            ->getRowArray();

        return $row ?: ['THN_SUB_RESTITUSI' => 0, 'BLN_SUB_RESTITUSI' => 0, 'HR_SUB_RESTITUSI' => 0];
    }

    /**
     * Hitung selisih hari antara dua tanggal menggunakan SQL DATEDIFF.
     * Port dari legacy yang menggunakan: SELECT DATEDIFF(?, ?) AS selisih
     */
    public function dateDiff(string $dateEnd, string $dateStart): int
    {
        $result = $this->db->query("SELECT DATEDIFF(?, ?) AS selisih", [$dateEnd, $dateStart])->getRow();
        return $result ? (int) $result->selisih : 0;
    }

    /**
     * Ambil total hari diluar UPT (tahanan luar / melarikan diri).
     * Port dari Func_ekspirasi::getTotalTahananLuar()
     */
    public function getTotalTahananLuar(string $idPerkara): int
    {
        $rows = $this->db->table('tahanan_luar')
            ->select('TGL_MULAI, TGL_SELESAI, ID_JENIS_TL')
            ->where('ID_PERKARA', $idPerkara)
            ->where('IS_DELETED', '0')
            ->get()
            ->getResultArray();

        $total = 0;
        foreach ($rows as $row) {
            if (!empty($row['TGL_MULAI']) && !empty($row['TGL_SELESAI'])) {
                $diff = $this->dateDiff($row['TGL_SELESAI'], $row['TGL_MULAI']);
                if ($diff > 0) {
                    $total += $diff;
                }
            }
        }

        return $total;
    }

    /**
     * Update tabel perkara dengan hasil perhitungan ekspirasi.
     */
    public function updatePerkaraEkspirasi(string $idPerkara, array $data): bool
    {
        return $this->db->table('perkara')
            ->where('ID_PERKARA', $idPerkara)
            ->update($data);
    }

    /**
     * Ambil data perkara detail (dengan join blok & sel) untuk registrasi BIII.
     */
    public function getPerkaraDetailBIII(string $idPerkara): ?array
    {
        $row = $this->db->query(
            'SELECT perkara.*, CONCAT(`blok`.`DESKRIPSI`, " ", `sel`.`NO_SEL`) AS LOKASI_SEL_DESKRIPSI
             FROM perkara
             LEFT JOIN blok ON perkara.LOKASI_BLOK = blok.ID_BLOK
             LEFT JOIN sel ON perkara.LOKASI_SEL = sel.ID_SEL
             WHERE perkara.ID_PERKARA = ? AND perkara.IS_DELETE = 0 AND perkara.ID_STATUS = "STA"',
            [$idPerkara]
        )->getRowArray();

        return $row ?: null;
    }
}
