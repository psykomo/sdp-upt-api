<?php

namespace App\Modules\Queue\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class ReplicaExporterService
{
    private BaseConnection $db;

    /**
     * Daftar tabel domain konsolidasi SDP beserta field primary key-nya.
     * Mengacu persis pada all_konsolidasi_tables_order di legacy CI 2 Pusat.
     */
    public const KONSOLIDASI_TABLES = [
        'identitas'                                     => 'NOMOR_INDUK',
        'perkara'                                       => 'ID_PERKARA',
        'hukuman'                                       => 'ID_HKMAN',
        'hukuman_detil'                                 => 'ID_DETIL_HUKUMAN',
        'denda'                                         => 'ID_DENDA',
        'uang_pengganti'                                => 'ID_UANG_PENGGANTI',
        'restitusi'                                     => 'ID_RESTITUSI',
        'history_registrasi'                            => 'ID_HISTORY_REG',
        'user_ttde'                                     => 'ID_USER_TTD',
        'kejahatan'                                     => 'ID_KEJAHATAN',
        'keputusan'                                     => 'ID_KEPUTUSAN',
        'remisi'                                        => 'ID_REMISI',
        'remisi_perubahan'                              => 'ID_REMISI_PERUBAHAN',
        'grasi'                                         => 'ID_GRASI',
        'cuti_pb'                                       => 'ID_CUTI',
        'pembebasan'                                    => 'ID_PEMBEBASAN',
        'perpanjangan_tahanan'                          => 'ID_PMT',
        'penahanan_lanjutan'                            => 'ID_PENAHANAN_LANJUTAN',
        'melarikan_diri'                                => 'ID_MELARIKAN_DIRI',
        'meninggal_dunia'                               => 'ID_MD',
        'tahanan_luar'                                  => 'ID_TAHANAN_LUAR',
        'mutasi_golongan'                               => 'ID_MUTASI_TAHANAN',
        'mutasi_upt_header'                             => 'ID_MUTASI_TAHANAN',
        'mutasi_upt_detail'                             => 'ID_MUTASI_UPT_HEADER',
        'mutasi_upt_detail_history'                     => 'ID_MUTASI_UPT_DETAIL_HISTORY',
        'pengunjung'                                    => 'ID_PENGUNJUNG',
        'kunjungan'                                     => 'ID_KUNJUNGAN',
        'kunjungan_pengikut'                            => 'ID_KUNJUNGAN_PENGIKUT',
        'kunjungan_titipan'                             => 'ID_TITIPAN',
        'pelanggaran'                                   => 'ID_PELANGGARAN',
        'sanksi'                                        => 'ID_SANKSI',
        'catatan'                                       => 'ID_CATATAN',
        'blok'                                          => 'ID_BLOK',
        'sel'                                           => 'ID_SEL',
        'mutasi_sel'                                    => 'ID_MUTASI_SEL',
        'kejahatan_narkotik'                            => 'ID_KEJAHATAN_NARKOTIK',
        'monitoring_aplikasi'                           => 'ID_MONITORING_APLIKASI',
        'monitoring_aplikasi_selfservice'               => 'ID_MONITORING_APLIKASI_SELFSERVICE',
        'log_biometric'                                 => 'ID_LOG',
        'bantuan_hukum'                                 => 'ID_BANTUAN_HUKUM',
        'permintaan_litmas'                             => 'ID_PERMINTAAN_LITMAS',
        'pendidikan_anak'                               => 'ID_PENDIDIKAN_ANAK',
        'pendidikan_anak_detail'                        => 'ID_PENDIDIKAN_ANAK_DETAIL',
        'asesmen_hasil_ispn'                            => 'ID_ASESMEN_HASIL_ISPN',
        'asesmen_hasil'                                 => 'ID_ASESMEN_HASIL',
        'asesmen_hasil_tindak_pidana'                   => 'ID_ISPN_TINDAK_PIDANA_PERKARA',
        'asesmen_hasil_risiko_anak'                     => 'ID_ASESMEN_HASIL_RISIKO_ANAK',
        'asesmen_hasil_anak'                            => 'ID_ASESMEN_HASIL_ANAK',
        'asesmen_hasil_anak_detail'                     => 'ID_ASESMEN_HASIL_ANAK_DETAIL',
        'asesmen_hasil_lembar_rekomendasi_anak'         => 'ID_ASESMEN_LEMBAR_REKOMENDASI',
        'asesmen_hasil_lembar_rekomendasi_anak_detail'  => 'ID_ASESMEN_LEMBAR_REKOMENDASI_DETAIL',
        'asesmen_hasil_ippa_anak'                       => 'ID_ASESMEN_IPPA',
        'asesmen_hasil_ippa_anak_detail'                => 'ID_ASESMEN_IPPA_DETAIL',
        'gizi_anak_bawaan'                              => 'ID_GIZI_ANAK_BAWAAN',
        'identitas_ktp'                                 => 'IDENTITAS_KTP_ID',
        'integrasi'                                     => 'ID_INTEGRASI',
        'integrasi_pidana'                              => 'ID_INTEGRASI_PIDANA',
        'integrasi_remisi'                              => 'ID_INTEGRASI_REMISI',
        'integrasi_rekomendasi'                         => 'ID_INTEGRASI_REKOMENDASI',
        'integrasi_sk_menteri'                          => 'ID_INTEGRASI_SK_MENTERI',
        'sidang_tpp'                                    => 'ID_SIDANG_TPP',
        'sidang_tpp_usulan'                             => 'ID_SIDANG_TPP_USULAN',
        'sidang_tpp_anggota'                            => 'ID_SIDANG_TPP_ANGGOTA',
        'sidang_tpp_rekomendasi'                        => 'ID_SIDANG_TPP_REKOMENDASI',
        'sidang_tpp_kanwil'                             => 'ID_SIDANG_TPP_KANWIL',
        'sidang_tpp_kanwil_usulan'                      => 'ID_SIDANG_TPP_KANWIL_USULAN',
        'sidang_tpp_kanwil_anggota'                     => 'ID_SIDANG_TPP_KANWIL_ANGGOTA',
        'sidang_tpp_kanwil_rekomendasi'                 => 'ID_SIDANG_TPP_KANWIL_REKOMENDASI',
        'sidang_tpp_ditjen'                             => 'ID_SIDANG_TPP_DITJEN',
        'sidang_tpp_ditjen_usulan'                      => 'ID_SIDANG_TPP_DITJEN_USULAN',
        'sidang_tpp_ditjen_anggota'                     => 'ID_SIDANG_TPP_DITJEN_ANGGOTA',
        'sidang_tpp_ditjen_rekomendasi'                 => 'ID_SIDANG_TPP_DITJEN_REKOMENDASI',
        'remisi_sk_kanwil'                              => 'ID_REMISI_SK_KANWIL',
        'remisi_sk_ditjen'                              => 'ID_REMISI_SK_DITJEN',
        'remisi_pencabutan'                             => 'ID_REMISI_PENCABUTAN',
        'remisi_pencabutan_detil'                       => 'ID_REMISI_PENCABUTAN_DETIL',
    ];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Ekspor data dari database replica UPT (db{satker_id}) ke kumpulan file CSV intermediate.
     *
     * @param  string   $satkerId   ID Satker/UPT (misal: 093 atau 379)
     * @param  string   $exportPath Direktori tujuan penyimpanan file CSV dan file INI
     * @param  callable $onProgress Callback status: fn(string $message, int $pct)
     * @return array    Statistik ekspor: ['tables' => array, 'total_rows' => int]
     */
    public function export(string $satkerId, string $exportPath, ?callable $onProgress = null): array
    {
        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0777, true);
        }

        $databaseUpt = 'db' . $satkerId;

        // Ambil daftar seluruh tabel yang ada di database replica
        $tablesQuery = $this->db->query("SHOW FULL TABLES FROM `{$databaseUpt}` WHERE `Table_type` = 'BASE TABLE'");
        $existingTables = [];
        foreach ($tablesQuery->getResultArray() as $row) {
            $tableName = reset($row);
            $existingTables[strtolower($tableName)] = $tableName;
        }

        $tablesFieldsIni = [];
        $tablesInfoIni   = [];
        $totalTables     = count(self::KONSOLIDASI_TABLES);
        $exportedTables  = [];
        $totalRows       = 0;
        $currentIdx      = 0;

        foreach (self::KONSOLIDASI_TABLES as $table => $fieldKey) {
            $currentIdx++;
            $tableLower = strtolower($table);

            // Jika tabel tidak ada di database replica UPT, lewati
            if (!isset($existingTables[$tableLower])) {
                continue;
            }

            $actualTableName = $existingTables[$tableLower];

            // Update persentase progres tahap ekspor CSV (30% sampai 60%)
            $pct = 30 + (int) (($currentIdx / $totalTables) * 30);
            if (is_callable($onProgress)) {
                $onProgress("Mengekspor tabel {$actualTableName} ke CSV...", $pct);
            }

            // Ambil daftar kolom tabel
            $colsQuery = $this->db->query("SHOW COLUMNS FROM `{$databaseUpt}`.`{$actualTableName}`");
            $columns = [];
            foreach ($colsQuery->getResultArray() as $colRow) {
                $columns[] = $colRow['Field'];
            }

            if (empty($columns)) {
                continue;
            }

            // Simpan metadata ke konfigurasi INI
            $tablesFieldsIni[$actualTableName] = $columns;
            $tablesInfoIni[$actualTableName]   = ['field_key' => $fieldKey];

            // Query seluruh baris dari tabel replica
            $selectCols = array_map(fn($c) => '`' . $c . '`', $columns);
            $colsSql = implode(', ', $selectCols);
            $dataQuery = $this->db->query("SELECT {$colsSql} FROM `{$databaseUpt}`.`{$actualTableName}`");

            $csvFile = $exportPath . DIRECTORY_SEPARATOR . $actualTableName . '.csv';
            $fp = fopen($csvFile, 'wb');
            if ($fp === false) {
                throw new RuntimeException("Gagal membuka file CSV untuk penulisan: {$csvFile}");
            }

            $rowCount = 0;
            while ($row = $dataQuery->getUnbufferedRow('array')) {
                $this->writeCsvLine($fp, $row);
                $rowCount++;
            }

            fclose($fp);

            $exportedTables[$actualTableName] = $rowCount;
            $totalRows += $rowCount;
        }

        // Tulis file metadata INI agar bisa dibaca oleh KonsolidasiRestoreHandler
        $this->writeIniFile($exportPath . DIRECTORY_SEPARATOR . 'tables_fields.ini', $tablesFieldsIni);
        $this->writeIniFile($exportPath . DIRECTORY_SEPARATOR . 'tables_info.ini', $tablesInfoIni);

        $generalInfo = [
            'general' => [
                'id_upt'     => $satkerId,
                'format'     => 'csv',
                'time_point' => date('Y-m-d H:i:s'),
            ]
        ];
        $this->writeIniFile($exportPath . DIRECTORY_SEPARATOR . 'info.ini', $generalInfo);

        return [
            'tables'     => $exportedTables,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Menulis satu baris CSV dengan format spesifik yang diharapkan oleh LOAD DATA LOCAL INFILE:
     * - Field enclosed by "
     * - Field terminated by ,
     * - Lines terminated by \r\n
     * - Nilai NULL ditulis sebagai \N
     */
    private function writeCsvLine($fp, array $row): void
    {
        $fields = [];
        foreach ($row as $val) {
            if ($val === null) {
                $fields[] = '\N';
            } else {
                // Escape tanda petik ganda
                $escaped = str_replace('"', '""', (string) $val);
                $fields[] = '"' . $escaped . '"';
            }
        }

        fwrite($fp, implode(',', $fields) . "\r\n");
    }

    /**
     * Menulis array asosiatif multidimensi ke format file INI standar.
     */
    private function writeIniFile(string $filePath, array $data): void
    {
        $content = '';
        foreach ($data as $section => $values) {
            $content .= "[{$section}]\r\n";
            if (is_array($values)) {
                foreach ($values as $key => $val) {
                    if (is_numeric($key)) {
                        $content .= "{$key} = \"{$val}\"\r\n";
                    } else {
                        $content .= "{$key} = \"{$val}\"\r\n";
                    }
                }
            }
            $content .= "\r\n";
        }

        file_put_contents($filePath, $content);
    }
}
