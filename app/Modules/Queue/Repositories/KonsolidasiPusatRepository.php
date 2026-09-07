<?php

namespace App\Modules\Queue\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Repository yang bertindak sebagai SDP Pusat untuk menyediakan
 * data perubahan kepada UPT / Kanwil.
 *
 * Logika ini adalah port dari backupExecute() di legacy Konsolidasi.php,
 * khususnya mode: $is_sdp_pusat = TRUE, $is_from_download_queue = TRUE.
 *
 * Format output (array per data_type) harus identik dengan yang
 * dikembalikan oleh controller api/changescompress di CI2,
 * karena sisi UPT (102sdp) memparse respons itu di readUPTChangesFromServer.
 */
final class KonsolidasiPusatRepository
{
    private BaseConnection $db;

    /** Folder kerja sementara untuk CSV & ZIP, di dalam WRITEPATH */
    private string $workDir;

    public function __construct()
    {
        $this->db      = Database::connect();
        $this->workDir = WRITEPATH . 'konsolidasi_tmp';
        if (!is_dir($this->workDir)) {
            mkdir($this->workDir, 0755, true);
        }
    }

    // -------------------------------------------------------------------------
    // Daftar tabel yang dikirimkan Pusat → UPT  (port dari backupExecute
    // ketika is_by_upt=TRUE, is_sdp_pusat=TRUE, is_from_download_queue=TRUE)
    // -------------------------------------------------------------------------

    /** Tabel 'data' yang dikirim Pusat ke UPT. Key = nama tabel, Value = primary key. */
    private function dataTablesForUpt(): array
    {
        return [
            'remisi'                     => 'ID_REMISI',
            'ma_penahanan'               => 'MA_PENAHANAN_GUID',
            'notifikasi'                 => 'ID_NOTIFIKASI',
            'notifikasi_detail'          => 'ID_NOTIFIKASI_DETAIL',
            'bps_ijin'                   => 'ID_BPS_IJIN',
            'bps_pencabutan'             => 'ID_BPS_PENCABUTAN',
            'log_arsip'                  => 'ID_LOG_ARSIP',
            // integrasi tables
            'integrasi_sidang'           => 'ID_INTEGRASI_SIDANG',
            'integrasi_sidang_detail'    => 'ID_INTEGRASI_SIDANG_DETAIL',
            'integrasi_sidang_hasil'     => 'ID_INTEGRASI_SIDANG_HASIL',
            'integrasi_sidang_ditjen'    => 'ID_INTEGRASI_SIDANG_DITJEN',
            'integrasi_sidang_ditjen_hasil' => 'ID_INTEGRASI_SIDANG_DITJEN_HASIL',
            'remisi_sk_kanwil'           => 'ID_REMISI_SK_KANWIL',
            'sidang_tpp_kanwil'          => 'ID_SIDANG_TPP_KANWIL',
            'sidang_tpp_kanwil_usulan'   => 'ID_SIDANG_TPP_KANWIL_USULAN',
            'remisi_sk_ditjen'           => 'ID_REMISI_SK_DITJEN',
            'sidang_tpp_ditjen'          => 'ID_SIDANG_TPP_DITJEN',
            'sidang_tpp_ditjen_usulan'   => 'ID_SIDANG_TPP_DITJEN_USULAN',
            'remisi_pencabutan'          => 'ID_REMISI_PENCABUTAN',
            'remisi_pencabutan_detil'    => 'ID_REMISI_PENCABUTAN_DETIL',
        ];
    }

    /** Tabel 'dokumen' yang dikirim Pusat ke UPT. */
    private function dokumenTablesForUpt(): array
    {
        return [
            'dokumen'                  => 'ID_DOKUMEN',
            'dokumen_elektronik_surat' => 'ID_DOKUMEN_SURAT',
        ];
    }

    /** Tabel 'data' yang dikirim Pusat ke Kanwil. */
    private function dataTablesForKanwil(): array
    {
        // Kanwil hanya menerima data integrasi
        return [
            'integrasi_sidang'           => 'ID_INTEGRASI_SIDANG',
            'integrasi_sidang_detail'    => 'ID_INTEGRASI_SIDANG_DETAIL',
            'integrasi_sidang_hasil'     => 'ID_INTEGRASI_SIDANG_HASIL',
            'integrasi_sidang_ditjen'    => 'ID_INTEGRASI_SIDANG_DITJEN',
            'integrasi_sidang_ditjen_hasil' => 'ID_INTEGRASI_SIDANG_DITJEN_HASIL',
            'remisi_sk_kanwil'           => 'ID_REMISI_SK_KANWIL',
            'sidang_tpp_kanwil'          => 'ID_SIDANG_TPP_KANWIL',
            'sidang_tpp_kanwil_usulan'   => 'ID_SIDANG_TPP_KANWIL_USULAN',
            'remisi_sk_ditjen'           => 'ID_REMISI_SK_DITJEN',
            'sidang_tpp_ditjen'          => 'ID_SIDANG_TPP_DITJEN',
            'sidang_tpp_ditjen_usulan'   => 'ID_SIDANG_TPP_DITJEN_USULAN',
        ];
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Buat paket perubahan (ZIP) untuk UPT tertentu dan kembalikan
     * struktur yang kompatibel dengan changescompress CI2.
     *
     * @param  string   $idUpt
     * @param  string[] $dataTypes  e.g. ['data', 'dokumen']
     * @return array<string, mixed>  Key = data_type; setiap elemen berisi
     *                               file_data (base64), table_backup_result, dll.
     */
    public function buildChangesForUpt(string $idUpt, array $dataTypes): array
    {
        return $this->build($idUpt, true, $dataTypes);
    }

    /**
     * Buat paket perubahan untuk Kanwil.
     */
    public function buildChangesForKanwil(string $idKanwil, array $dataTypes): array
    {
        return $this->build($idKanwil, false, $dataTypes);
    }

    /**
     * Tandai tabel sudah berhasil di-restore oleh UPT/Kanwil.
     * Port dari backupMarkSuccessFlagLogForMarkQueue().
     */
    public function markReaded(string $satkerId, bool $isUpt, string $tableName, string $tempPath): void
    {
        // Tandai baris di log_konsolidasi_for_download
        $this->markLogForTable($satkerId, $isUpt, $tableName);
    }

    /**
     * Hapus folder temp setelah seluruh tabel berhasil di-restore.
     * Port dari backupClearTemporaryForMarkQueue().
     */
    public function clearTemp(string $tempPath): void
    {
        if (!empty($tempPath) && is_dir($tempPath)) {
            $this->deleteDir($tempPath);
        }
    }

    // -------------------------------------------------------------------------
    // Core build logic
    // -------------------------------------------------------------------------

    /**
     * Core: buat ZIP per data_type, kembalikan array berisi base64.
     */
    private function build(string $satkerId, bool $isUpt, array $dataTypes): array
    {
        $result = [];

        foreach ($dataTypes as $dataType) {
            // Tentukan tabel yang akan diproses
            if ($dataType === 'data') {
                $tables = $isUpt ? $this->dataTablesForUpt() : $this->dataTablesForKanwil();
            } elseif ($dataType === 'dokumen') {
                if (!$isUpt) {
                    // Kanwil tidak dapat dokumen dari Pusat
                    continue;
                }
                $tables = $this->dokumenTablesForUpt();
            } else {
                continue;
            }

            // Buat direktori temp unik untuk sesi ini
            $sessionId   = $satkerId . '_' . date('YmdHis') . '_' . rand(100, 999);
            $tempDir     = $this->workDir . '/' . $sessionId;
            $archivePath = $this->workDir . '/' . $sessionId . '.sdp';

            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tableBackupResult = [];
            $totalRows         = 0;

            foreach ($tables as $tableName => $primaryKey) {
                // Cek apakah tabel ada di DB
                if (!$this->db->tableExists($tableName)) {
                    $tableBackupResult[$tableName] = ['num_rows' => 0, 'file_address' => null];
                    continue;
                }

                $csvPath = $tempDir . '/' . $tableName . '.csv';
                $numRows = $this->exportTableToCsv(
                    $tableName,
                    $primaryKey,
                    $satkerId,
                    $isUpt,
                    $csvPath,
                    $dataType
                );

                $tableBackupResult[$tableName] = [
                    'num_rows'     => $numRows,
                    'file_address' => $numRows > 0 ? $csvPath : null,
                    'timestamp_max' => null,
                ];
                $totalRows += $numRows;
            }

            // Jika tidak ada data sama sekali, hapus temp dan set file_data = null
            if ($totalRows === 0) {
                $this->deleteDir($tempDir);
                $result[$dataType] = [
                    'file_data'           => null,
                    'file_address'        => null,
                    'table_backup_result' => $tableBackupResult,
                    'for_download_target_temp_path' => null,
                    'status'              => true,
                    'num_rows'            => 0,
                ];
                continue;
            }

            // Tambahkan metadata info.ini (mirip backupInfoToFile)
            $infoContent = $this->buildIni([
                'satker_id' => $satkerId,
                'data_type' => $dataType,
                'periode'   => date('Y-m-d'),
            ]);
            file_put_contents($tempDir . '/info.ini', $infoContent);

            // Kompres dengan 7za.exe (password SDP)
            $compressed = $this->compressDir($tempDir, $archivePath);

            if (!$compressed) {
                $this->deleteDir($tempDir);
                log_message('error', "[KonsolidasiPusatRepository] Gagal compress untuk {$satkerId} / {$dataType}");
                $result[$dataType] = [
                    'file_data'           => null,
                    'file_address'        => null,
                    'table_backup_result' => $tableBackupResult,
                    'for_download_target_temp_path' => $tempDir,
                    'status'              => false,
                    'num_rows'            => $totalRows,
                ];
                continue;
            }

            // Encode ke base64
            $fileData = base64_encode(file_get_contents($archivePath));

            // Hapus temp dir dan file archive
            $this->deleteDir($tempDir);
            @unlink($archivePath);

            // file_address: beri nama file agar get_file_name() di 102sdp bisa memparse-nya.
            // Format legacy: {satker_id}_{datetime}_{data_type}.sdp
            $fileName = $satkerId . '_' . date('Y-m-d_H-i-s') . '_' . $dataType . '.sdp';

            $result[$dataType] = [
                'file_data'           => $fileData,
                'file_address'        => 'sdp_konsolidasi/' . $fileName,
                'table_backup_result' => $tableBackupResult,
                'for_download_target_temp_path' => null,
                'status'              => true,
                'num_rows'            => $totalRows,
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // CSV Export
    // -------------------------------------------------------------------------

    /**
     * Ekspor satu tabel ke file CSV, dengan filter log_konsolidasi_for_download.
     * Port dari backupTableToFile() mode is_sdp_pusat=TRUE.
     *
     * @return int  Jumlah baris yang di-ekspor
     */
    private function exportTableToCsv(
        string $tableName,
        string $primaryKey,
        string $satkerId,
        bool   $isUpt,
        string $csvPath,
        string $dataType
    ): int {
        // Ambil semua kolom tabel
        $colQuery = $this->db->query("SHOW COLUMNS FROM `{$tableName}`");
        if (!$colQuery) {
            return 0;
        }
        $columns = array_column($colQuery->getResultArray(), 'Field');
        if (empty($columns)) {
            return 0;
        }

        // Hitung dulu jumlah baris yang tersedia
        $countBuilder = $this->db->table($tableName . ' target')
            ->join('log_konsolidasi_for_download log', "log.KEY_VALUE = target.`{$primaryKey}`")
            ->where('log.TABLE_NAME', $tableName);

        if ($isUpt) {
            $countBuilder->where('log.ID_UPT', $satkerId)->where('log.IS_UPT_DOWNLOADED', 0);
        } else {
            $countBuilder->where('log.ID_KANWIL', $satkerId)->where('log.IS_KANWIL_DOWNLOADED', 0);
        }

        $numRows = $countBuilder->countAllResults(false);
        if ($numRows === 0) {
            return 0;
        }

        // Buat kolom aliased untuk SELECT
        $colSelects = array_map(fn($c) => "target.`{$c}`", $columns);

        // Coba SELECT INTO OUTFILE (paling cepat, tersedia di local DB)
        $csvPathEscaped = str_replace('\\', '/', $csvPath);
        $colList        = implode(', ', $colSelects);

        $sql = "SELECT {$colList}
                INTO OUTFILE '" . addslashes($csvPathEscaped) . "'
                FIELDS TERMINATED BY ','
                ENCLOSED BY '\"'
                ESCAPED BY '\\\\'
                LINES TERMINATED BY '\\r\\n'
                FROM `{$tableName}` target
                JOIN log_konsolidasi_for_download log
                    ON log.KEY_VALUE = target.`{$primaryKey}`
                WHERE log.TABLE_NAME = '{$tableName}'";

        if ($isUpt) {
            $sql .= " AND log.ID_UPT = '" . $this->db->escapeString($satkerId) . "'";
            $sql .= " AND log.IS_UPT_DOWNLOADED = 0";
        } else {
            $sql .= " AND log.ID_KANWIL = '" . $this->db->escapeString($satkerId) . "'";
            $sql .= " AND log.IS_KANWIL_DOWNLOADED = 0";
        }

        $ok = @$this->db->simpleQuery($sql);

        // Jika INTO OUTFILE gagal (hosted DB, permission, dll), fallback ke PHP export
        if (!$ok || !file_exists($csvPath)) {
            $numRows = $this->exportTableToCsvPhp(
                $tableName, $primaryKey, $satkerId, $isUpt, $columns, $csvPath
            );
        } else {
            // Hitung baris dari file CSV yang sudah dibuat
            $numRows = max(0, count(file($csvPath)) - 0);
        }

        return $numRows;
    }

    /**
     * Fallback: ekspor menggunakan PHP (untuk hosted / permission-restricted DB).
     */
    private function exportTableToCsvPhp(
        string $tableName,
        string $primaryKey,
        string $satkerId,
        bool   $isUpt,
        array  $columns,
        string $csvPath
    ): int {
        $builder = $this->db->table($tableName . ' target')
            ->select(array_map(fn($c) => "target.`{$c}`", $columns), false)
            ->join('log_konsolidasi_for_download log', "log.KEY_VALUE = target.`{$primaryKey}`")
            ->where('log.TABLE_NAME', $tableName);

        if ($isUpt) {
            $builder->where('log.ID_UPT', $satkerId)->where('log.IS_UPT_DOWNLOADED', 0);
        } else {
            $builder->where('log.ID_KANWIL', $satkerId)->where('log.IS_KANWIL_DOWNLOADED', 0);
        }

        $query = $builder->get();
        $rows  = $query->getResultArray();

        if (empty($rows)) {
            return 0;
        }

        $fp = fopen($csvPath, 'w');
        if (!$fp) {
            return 0;
        }
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row), ',', '"', '\\');
        }
        fclose($fp);

        return count($rows);
    }

    // -------------------------------------------------------------------------
    // Markqueue
    // -------------------------------------------------------------------------

    /**
     * Update IS_UPT_DOWNLOADED atau IS_KANWIL_DOWNLOADED = 1 untuk tabel tertentu.
     */
    private function markLogForTable(string $satkerId, bool $isUpt, string $tableName): void
    {
        $builder = $this->db->table('log_konsolidasi_for_download')
            ->where('TABLE_NAME', $tableName);

        if ($isUpt) {
            $builder->where('ID_UPT', $satkerId)->where('IS_UPT_DOWNLOADED', 0);
            $builder->update(['IS_UPT_DOWNLOADED' => 1, 'UPT_DOWNLOAD_DATE' => date('Y-m-d H:i:s')]);
        } else {
            $builder->where('ID_KANWIL', $satkerId)->where('IS_KANWIL_DOWNLOADED', 0);
            $builder->update(['IS_KANWIL_DOWNLOADED' => 1, 'KANWIL_DOWNLOAD_DATE' => date('Y-m-d H:i:s')]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Password default enkripsi arsip konsolidasi SDP (format .sdp) */
    private string $archivePassword = 'D9OxN12RcT84sOghHyF';

    /**
     * Cari lokasi executable 7za.exe.
     */
    private function find7zaBinary(): ?string
    {
        $custom = trim(env('SDP_7ZA_PATH') ?? '', " \t\n\r\0\x0B\"'");
        if (!empty($custom)) {
            $real = realpath($custom);
            if ($real && file_exists($real)) {
                return $real;
            }
            if (file_exists($custom)) {
                return $custom;
            }
        }

        return null;
    }

    /**
     * Kompres semua isi direktori ke file .sdp menggunakan 7za.exe dengan password SDP.
     * Format ini kompatibel 100% dengan restoreExecute() di 102sdp UPT tanpa perlu
     * mengubah kode legacy sama sekali.
     */
    private function compressDir(string $sourceDir, string $destFile): bool
    {
        $sevenZip = $this->find7zaBinary();

        if ($sevenZip !== null) {
            $isWindows = (DIRECTORY_SEPARATOR === '\\');
            $destPath = $isWindows ? str_replace('/', '\\', $destFile) : $destFile;
            $sourcePattern = $isWindows
                ? (rtrim(str_replace('/', '\\', $sourceDir), '\\') . '\\*')
                : (rtrim($sourceDir, '/') . '/*');

            $cmd = sprintf(
                '"%s" a -p%s "%s" "%s"',
                $sevenZip,
                $this->archivePassword,
                $destPath,
                $sourcePattern
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($destFile)) {
                return true;
            }

            log_message('error', "[KonsolidasiPusatRepository] 7za command failed: {$cmd}, code: {$returnCode}");
        }

        // Fallback jika ZipArchive tersedia di PHP environment
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($destFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($files as $file) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($sourceDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
                $zip->close();
                return file_exists($destFile);
            }
        }

        return false;
    }

    /**
     * Format array ke INI string sederhana (mirip get_ini_file() CI2).
     */
    private function buildIni(array $data, bool $nested = false): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $lines[] = "[{$key}]";
                foreach ($value as $k => $v) {
                    $lines[] = "{$k} = \"{$v}\"";
                }
            } else {
                $lines[] = "{$key} = \"{$value}\"";
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Hapus direktori beserta isinya.
     */
    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
