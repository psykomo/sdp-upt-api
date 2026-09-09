<?php

namespace App\Modules\Queue\Handlers;

use App\Modules\Queue\Repositories\QueueRepository;
use App\Modules\Perkara\Services\EkspirasiCalculatorService;
use App\Modules\Perkara\Repositories\EkspirasiRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Exception;

use App\Modules\Queue\Services\BinlogExecutorService;
use App\Modules\Queue\Services\ReplicaExporterService;

final class KonsolidasiRestoreHandler implements JobHandlerInterface
{
    private QueueRepository $repository;
    private BaseConnection $db;

    // Temporary storage for parsed ini configs
    private ?array $tablesFields = null;
    private ?array $tablesInfo = null;
    private ?array $infoConfig = null;

    // Track perkara for ekspirasi recalculation
    private array $perkaraToRecalculate = [];
    private BinlogExecutorService $binlogExecutor;

    public function __construct()
    {
        $this->repository = new QueueRepository();
        $this->db = Database::connect(); // Using shared_database
        $this->binlogExecutor = new BinlogExecutorService($this->db);
    }

    public function handle(array $payload, string $jobId): void
    {
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $filePath = $payload['file_path'] ?? '';
        $satkerId = $payload['satker_id'] ?? '';
        $dataType = $payload['data_type'] ?? 'data';

        if (empty($filePath) || !file_exists($filePath)) {
            throw new \RuntimeException("Zip file not found at: {$filePath}");
        }

        $this->repository->updateProgress($jobId, 5, 'Mengekstrak file ZIP...');
        $restoreTempPath = $this->extractZip($filePath);
        $intermediateCsvPath = null;
        $isBinlog = false;

        try {
            $this->repository->updateProgress($jobId, 10, 'Membaca konfigurasi paket konsolidasi...');
            
            // Parse info.ini and tables_fields.ini
            $this->loadIniConfigs($restoreTempPath);

            $sourceCsvPath = $restoreTempPath;

            if (empty($this->tablesFields)) {
                $format = $this->infoConfig['general']['format'] ?? '';
                if ($format === 'bin-log' || $format === 'sql') {
                    $isBinlog = true;

                    // 1. TAHAP REPLAY BINLOG (10% - 30%)
                    $files = $this->infoConfig['files'] ?? [];
                    $clientDb = $this->infoConfig['general']['database_name'] ?? 'db_sdp';
                    $timePoint = $this->infoConfig['general']['time_point'] ?? date('Y-m-d H:i:s');
                    $method = $this->infoConfig['general']['method'] ?? 'partial';
                    $isFull = ($method === 'full');
                    $isRetry = (bool) ($payload['is_retry'] ?? false);

                    // Validasi urutan sequence
                    $this->binlogExecutor->validateSequence($satkerId, $files, $isRetry, $isFull);

                    // Replay binlog ke db{satkerId}
                    $this->binlogExecutor->replay(
                        $restoreTempPath,
                        $satkerId,
                        $files,
                        $format,
                        $clientDb,
                        $timePoint,
                        function (string $msg, int $pct) use ($jobId) {
                            $this->repository->updateProgress($jobId, $pct, $msg);
                        }
                    );

                    // 2. TAHAP EKSPOR REPLICA KE CSV INTERMEDIATE (30% - 60%)
                    $intermediateCsvPath = WRITEPATH . 'temp/intermediate_csv_' . uniqid();
                    $replicaExporter = new ReplicaExporterService($this->db);
                    $replicaExporter->export(
                        $satkerId,
                        $intermediateCsvPath,
                        function (string $msg, int $pct) use ($jobId) {
                            $this->repository->updateProgress($jobId, $pct, $msg);
                        }
                    );

                    // Muat konfigurasi hasil ekspor CSV
                    $this->loadIniConfigs($intermediateCsvPath);
                    $sourceCsvPath = $intermediateCsvPath;
                } else {
                    throw new \RuntimeException("Konfigurasi tables_fields.ini tidak ditemukan atau kosong.");
                }
            }

            // Determine if sender is UPT or Kanwil
            $isFromUpt = $this->isFromUpt($satkerId);

            $tables = array_keys($this->tablesFields ?? []);
            $total = count($tables);

            // 3. TAHAP IMPORT KE DATABASE PUSAT (60% - 90% jika binlog, 15% - 95% jika CSV)
            $basePct = $isBinlog ? 60 : 15;
            $rangePct = $isBinlog ? 30 : 80;

            foreach ($tables as $index => $tableName) {
                $pct = $basePct + (int) ((($index + 1) / ($total ?: 1)) * $rangePct);
                $this->repository->updateProgress($jobId, $pct, "Mengimpor tabel {$tableName} ke database pusat...");

                $csvFileName = "{$tableName}.csv";
                $this->restoreFileToTable($sourceCsvPath, $csvFileName, $tableName, $dataType, $satkerId, $isFromUpt);
            }

            // TODO: Integrasi remisi (update status persetujuan, dll) kalau perlu

            // 4. Post-import side effects: Recalculate Ekspirasi (90% - 100%)
            if (!empty($this->perkaraToRecalculate)) {
                $uniquePerkara = array_unique($this->perkaraToRecalculate);
                $totalPerkara = count($uniquePerkara);
                $this->repository->updateProgress($jobId, 95, "Recalculating ekspirasi untuk {$totalPerkara} perkara...");
                
                $calculator = new EkspirasiCalculatorService(new EkspirasiRepository());
                
                foreach ($uniquePerkara as $idPerkara) {
                    try {
                        $hasil = $calculator->calculatorEkspirasi($idPerkara);
                        if (!empty($hasil)) {
                            // Update perkara table with calculated values
                            $updateData = [
                                'TGL_EKSPIRASI_SEBENARNYA' => $hasil['hasil_awal'] ?? null,
                                'TGL_EKSPIRASI' => $hasil['hasil'] ?? null,
                                'TGL_SEPERTIGA' => $hasil['hasil1_3'] ?? null,
                                'TGL_SETENGAH' => $hasil['hasil1_2'] ?? null,
                                'TGL_DUAPERTIGA' => $hasil['hasil2_3'] ?? null,
                            ];
                            $this->db->table('perkara')
                                ->where('ID_PERKARA', $idPerkara)
                                ->update($updateData);
                        }
                    } catch (Exception $e) {
                        log_message('error', "Gagal recalculate ekspirasi perkara {$idPerkara}: " . $e->getMessage());
                    }
                }
            }

            $successMessage = $isBinlog
                ? 'Konsolidasi binlog selesai dan berhasil diintegrasikan ke database pusat.'
                : 'Konsolidasi selesai.';
            $this->repository->updateProgress($jobId, 100, $successMessage);
        } finally {
            $this->cleanupTemp($restoreTempPath);
            if ($intermediateCsvPath !== null) {
                $this->cleanupTemp($intermediateCsvPath);
            }
        }
    }


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

    private function extractZip(string $filePath): string
    {
        $tempPath = WRITEPATH . 'temp/restore_' . uniqid();
        if (!is_dir($tempPath)) {
            mkdir($tempPath, 0777, true);
        }

        $sevenZip = $this->find7zaBinary();
        if ($sevenZip !== null) {
            $passwords   = ['D9OxN12RcT84sOghHyF', 'AKdL342LKJSFL34kdfL', 'UUCIX6570KHIYU5QqwrhkRGKJGFR324234k'];
            $isWindows   = (DIRECTORY_SEPARATOR === '\\');
            $filePathCmd = $isWindows ? str_replace('/', '\\', $filePath) : $filePath;
            $tempPathCmd = $isWindows ? str_replace('/', '\\', $tempPath) : $tempPath;

            foreach ($passwords as $pwd) {
                $cmd = sprintf('"%s" x -p%s "%s" -o"%s" -y', $sevenZip, $pwd, $filePathCmd, $tempPathCmd);
                exec($cmd, $output, $code);
                if ($code === 0 && (file_exists($tempPath . '/info.ini') || file_exists($tempPath . '/tables_fields.ini') || file_exists($tempPath . '/binary'))) {
                    return $tempPath;
                }
            }
        }

        // Fallback jika unencrypted zip dan ZipArchive tersedia di PHP
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === TRUE) {
                $zip->extractTo($tempPath);
                $zip->close();
                return $tempPath;
            }
        }

        throw new \RuntimeException("Gagal membuka atau mengekstrak file konsolidasi: {$filePath}");
    }

    private function loadIniConfigs(string $path): void
    {
        $infoFile = $path . '/info.ini';
        if (file_exists($infoFile)) {
            $this->infoConfig = parse_ini_file($infoFile, true) ?: null;
        }

        $fieldsFile = $path . '/tables_fields.ini';
        if (file_exists($fieldsFile)) {
            $this->tablesFields = parse_ini_file($fieldsFile, true) ?: null;
        }

        $tablesInfoFile = $path . '/tables_info.ini';
        if (file_exists($tablesInfoFile)) {
            $this->tablesInfo = parse_ini_file($tablesInfoFile, true) ?: null;
        }
    }

    private function isFromUpt(string $satkerId): bool
    {
        // Jika ada di tabel kanwil, berarti dari Kanwil (false). Jika tidak, dari UPT (true).
        $row = $this->db->table('kanwil')->where('KODE', $satkerId)->get()->getRow();
        return $row === null;
    }

    private function restoreFileToTable(
        string $restoreTempPath, 
        string $fileName, 
        string $tableName, 
        string $dataType, 
        string $satkerId, 
        bool $isFromUpt
    ): void {
        $csvFileAddress = $restoreTempPath . '/' . $fileName;
        
        if (!file_exists($csvFileAddress)) {
            return; // Skip if file is missing
        }

        if (filesize($csvFileAddress) == 0) {
            return; // Skip empty files
        }

        // 1. File Copy Handling (Images, Sidik Jari, Dokumen)
        if ($tableName === 'identitas' && $dataType === 'image') {
            $sourceDir = $restoreTempPath . '/upload/';
            $destDir = FCPATH . '../upload/' . $satkerId . '/'; // Asumsi FCPATH menunjuk ke sdp-upt-api/public
            $this->copyDirectory($sourceDir, $destDir);
            return; // Gambar selesai, tidak insert CSV identitas
        }

        if ($tableName === 'sidik_jari' && $dataType === 'sidik_jari') {
            $this->copyDirectory($restoreTempPath . '/sidikjari/', FCPATH . '../sidikjari/');
        }

        if (($tableName === 'dokumen' || $tableName === 'dokumen_elektronik_surat') && $dataType === 'dokumen') {
            if ($tableName === 'dokumen') {
                $this->copyDirectory($restoreTempPath . '/upload/dokumen/', FCPATH . '../upload/dokumen/');
            }
            if ($tableName === 'dokumen_elektronik_surat') {
                $this->copyDirectory($restoreTempPath . '/surat_elektronik/', FCPATH . '../surat_elektronik/');
            }
        }

        // 2. Access Control Validation (Pusat Only rules)
        if (!$this->validateAccessControl($tableName, $isFromUpt)) {
            return;
        }

        // 3. Database import preparation
        $tableInfo = $this->tablesInfo[$tableName] ?? [];
        $fieldKey = $tableInfo['field_key'] ?? 'UNKNOWN_PK';
        $remoteFields = $this->tablesFields[$tableName] ?? [];

        // Check local fields
        $localFields = [];
        $columns = $this->db->query("SHOW COLUMNS FROM `{$tableName}`")->getResultArray();
        foreach ($columns as $col) {
            $localFields[$col['Field']] = true;
        }

        // Generate LOAD DATA LOCAL INFILE fields
        $fieldsMap = [];
        foreach ($remoteFields as $idx => $fieldName) {
            // Mapping rules similar to legacy: match PK and specific allowed fields
            if ($fieldName === $fieldKey || isset($localFields[$fieldName])) {
                $fieldsMap[] = '`' . $fieldName . '`';
            } else {
                $fieldsMap[] = '@var_' . $idx; // Ignore field
            }
        }

        $fieldsStr = implode(",", $fieldsMap);

        // 4. Temporary Table Creation
        $tempTableName = "_tmp_{$tableName}_" . bin2hex(random_bytes(4));
        $this->db->query("DROP TABLE IF EXISTS `{$tempTableName}`");
        $this->db->query("CREATE TABLE `{$tempTableName}` LIKE `{$tableName}`");

        try {
            // 5. LOAD DATA LOCAL INFILE ke Temporary Table via mysql CLI
            $escapedPath = str_replace('\\', '/', $csvFileAddress);
            $sqlLoadTemp = "
                LOAD DATA LOCAL INFILE '{$escapedPath}'
                INTO TABLE `{$tempTableName}`
                FIELDS TERMINATED BY ','
                ENCLOSED BY '\"'
                LINES TERMINATED BY '\\r\\n'
                ({$fieldsStr});
            ";
            $this->executeSqlViaCli($sqlLoadTemp);

            // 6. Validasi Data Khusus (Pusat Only)
            $isValidated = false;

            if ($tableName === 'integrasi' || $tableName === 'integrasi_pidana' || $tableName === 'integrasi_remisi') {
                // Blokir data yang sudah diedit Ditjen/Kanwil
                $whereEdit = "(inte.IS_DITJEN_EDIT = 1";
                if ($isFromUpt) {
                    $whereEdit .= " OR inte.IS_KANWIL_EDIT = 1";
                }
                $whereEdit .= ")";

                $sqlBlockInteg = "
                    DELETE tmp FROM `{$tempTableName}` tmp
                    INNER JOIN `integrasi` inte ON inte.ID_INTEGRASI = tmp.ID_INTEGRASI
                    WHERE {$whereEdit}
                ";
                $this->db->query($sqlBlockInteg);
                $isValidated = true;
            }

            if ($tableName === 'perkara') {
                // Blokir perkara mutasi (STTA) yang ID_UPT nya beda
                $sqlBlockPerkara = "
                    DELETE tmp FROM `{$tempTableName}` tmp
                    INNER JOIN `perkara` perk ON perk.{$fieldKey} = tmp.{$fieldKey}
                    WHERE tmp.ID_STATUS = 'STTA' AND tmp.ID_UPT <> perk.ID_UPT
                ";
                $this->db->query($sqlBlockPerkara);
                $isValidated = true;
            }

            // 7. Insert Data ke Tabel Utama
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");

            if (in_array($tableName, ['integrasi_sidang_ditjen_hasil', 'integrasi', 'sidang_tpp_usulan'])) {
                // Mode UPDATE spesifik kolom (INSERT ON DUPLICATE KEY UPDATE)
                $updateFields = [];
                foreach ($remoteFields as $fieldName) {
                    if ($fieldName === $fieldKey || $fieldName === 'KONSOLIDASI' || !isset($localFields[$fieldName])) continue;
                    $updateFields[] = "`{$fieldName}` = VALUES(`{$fieldName}`)";
                }
                $updateFields[] = "`KONSOLIDASI` = 2";
                
                $updateStr = implode(", ", $updateFields);
                $sqlInsert = "
                    INSERT INTO `{$tableName}`
                    SELECT * FROM `{$tempTableName}`
                    ON DUPLICATE KEY UPDATE {$updateStr}
                ";
                $this->db->query($sqlInsert);

            } else {
                // Mode REPLACE INTO (Natural Tables)
                // Pre-delete handlers
                if ($tableName === 'mutasi_upt_detail' || $tableName === 'sidik_jari') {
                    $this->db->query("DELETE tgt FROM `{$tableName}` tgt INNER JOIN `{$tempTableName}` tmp ON tgt.{$fieldKey} = tmp.{$fieldKey}");
                }
                if (strpos($tableName, 'daf_') === 0) { // daf tables
                    $this->db->query("DELETE tgt FROM `{$tableName}` tgt INNER JOIN `{$tempTableName}` tmp ON tgt.ID_UPT = tmp.ID_UPT AND tgt.TAHUN = tmp.TAHUN AND tgt.BULAN = tmp.BULAN");
                }

                if (isset($localFields['KONSOLIDASI'])) {
                    $this->db->query("UPDATE `{$tempTableName}` SET `KONSOLIDASI` = 2");
                }
                if (isset($localFields['KONSOLIDASI_IMAGE'])) {
                    $this->db->query("UPDATE `{$tempTableName}` SET `KONSOLIDASI_IMAGE` = 2");
                }

                $this->db->query("REPLACE INTO `{$tableName}` SELECT * FROM `{$tempTableName}`");
            }

            // Track ID_PERKARA that needs ekspirasi recalculation
            if (in_array($tableName, [
                'remisi', 'sidang_tpp_usulan', 'sidang_tpp_kanwil_usulan', 'sidang_tpp_ditjen_usulan', 
                'remisi_sk_kanwil', 'remisi_sk_ditjen', 'remisi_sk', 'persetujuan'
            ])) {
                $pk = $fieldKey;
                // Jika ada field ID_PERKARA, ambil dari temp table
                if (isset($localFields['ID_PERKARA'])) {
                    $query = $this->db->query("SELECT DISTINCT ID_PERKARA FROM `{$tempTableName}` WHERE ID_PERKARA IS NOT NULL AND ID_PERKARA != ''");
                    foreach ($query->getResultArray() as $row) {
                        $this->perkaraToRecalculate[] = $row['ID_PERKARA'];
                    }
                } elseif ($tableName === 'perkara') {
                    $query = $this->db->query("SELECT {$pk} AS ID_PERKARA FROM `{$tempTableName}`");
                    foreach ($query->getResultArray() as $row) {
                        $this->perkaraToRecalculate[] = $row['ID_PERKARA'];
                    }
                }
            }

            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        } finally {
            $this->db->query("DROP TABLE IF EXISTS `{$tempTableName}`");
        }
    }

    private function validateAccessControl(string $tableName, bool $isFromUpt): bool
    {
        // Rule: Milik Ditjen, tidak boleh dari UPT maupun Kanwil
        if (in_array($tableName, [
            'integrasi_sidang_ditjen', 'integrasi_sk_ditjen', 'integrasi_persetujuan_ditjen', 'integrasi_persetujuan_ditjen_hasil',
            'sidang_tpp_ditjen', 'sidang_tpp_ditjen_usulan', 'remisi_sk_ditjen', 'persetujuan', 'persetujuan_detail',
            'verifikasi', 'verifikasi_history'
        ])) {
            log_message('info', "Data [{$tableName}] milik pusat tidak dapat diakses, dilewati.");
            return false;
        }

        if ($isFromUpt) {
            // Rule: Milik Kanwil, tidak boleh dari UPT
            if (in_array($tableName, [
                'integrasi_sidang_kanwil', 'integrasi_sidang_kanwil_hasil', 'integrasi_sk_kanwil', 'integrasi_penetapan_persetujuan_kanwil',
                'sidang_tpp_kanwil', 'sidang_tpp_kanwil_usulan', 'remisi_sk_kanwil', 'remisi_otorisasi_sk'
            ])) {
                log_message('info', "Data [{$tableName}] milik kanwil tidak dapat diakses oleh UPT, dilewati.");
                return false;
            }
        } else {
            // Rule: Milik UPT, tidak boleh dari Kanwil
            if (in_array($tableName, [
                'integrasi_sidang_upt', 'integrasi_sidang_upt_hasil', 'sidang_tpp_upt', 'sidang_tpp_upt_usulan'
            ])) {
                log_message('info', "Data [{$tableName}] milik UPT tidak dapat diakses oleh Kanwil, dilewati.");
                return false;
            }
            
            // Allowlist untuk Kanwil: hanya integrasi, sidangtpp, dan dokumen
            if (strpos($tableName, 'integrasi') !== 0 && strpos($tableName, 'sidang_tpp') !== 0 && strpos($tableName, 'dokumen') !== 0) {
                log_message('info', "Data [{$tableName}] bukan bagian dari integrasi/sidang tpp tidak dapat diakses oleh Kanwil, dilewati.");
                return false;
            }
        }

        return true;
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($source)) return;
        if (!is_dir($dest)) mkdir($dest, 0777, true);

        $dir = opendir($source);
        while (false !== ($file = readdir($dir))) {
            if ($file !== '.' && $file !== '..') {
                $srcFile = $source . '/' . $file;
                $destFile = $dest . '/' . $file;
                if (is_dir($srcFile)) {
                    $this->copyDirectory($srcFile, $destFile);
                } else {
                    copy($srcFile, $destFile);
                }
            }
        }
        closedir($dir);
    }

    private function cleanupTemp(string $tempPath): void
    {
        if (is_dir($tempPath)) {
            $this->deleteDirectory($tempPath);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!file_exists($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    /**
     * Eksekusi perintah SQL secara langsung via CLI MySQL client.
     * Digunakan untuk operasi yang membutuhkan LOAD DATA LOCAL INFILE tanpa terhalang setting driver PHP.
     */
    private function executeSqlViaCli(string $sql): void
    {
        $mysqlBin = $this->binlogExecutor->getBinaryPath('mysql');
        $dbConfig = config('Database')->default;

        $host     = $dbConfig['hostname'] ?? '127.0.0.1';
        $port     = (int) ($dbConfig['port'] ?? 3306);
        $user     = $dbConfig['username'] ?? 'root';
        $pass     = $dbConfig['password'] ?? '';
        $database = $dbConfig['database'] ?? 'db_sdp_pusat';

        $isWindows = (DIRECTORY_SEPARATOR === '\\');
        $tempDir = WRITEPATH . 'temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $tempSqlFile = $tempDir . DIRECTORY_SEPARATOR . 'cli_' . bin2hex(random_bytes(6)) . '.sql';
        file_put_contents($tempSqlFile, $sql);

        $connArgs = sprintf('--host="%s" --port=%d --user="%s"', $host, $port, $user);
        if ($pass !== '') {
            $connArgs .= sprintf(' --password="%s"', addcslashes($pass, '"\\'));
        }

        $tempSqlPathCmd = $isWindows ? str_replace('/', '\\', $tempSqlFile) : $tempSqlFile;
        $errorLog       = $tempDir . DIRECTORY_SEPARATOR . 'cli_err_' . bin2hex(random_bytes(6)) . '.log';
        $errorLogCmd    = $isWindows ? str_replace('/', '\\', $errorLog) : $errorLog;

        $cmd = sprintf(
            '"%s" --local-infile %s %s < "%s" 2> "%s"',
            $mysqlBin,
            $connArgs,
            $database,
            $tempSqlPathCmd,
            $errorLogCmd
        );

        exec($cmd, $output, $code);

        @unlink($tempSqlFile);
        $errContent = file_exists($errorLog) ? file_get_contents($errorLog) : '';
        @unlink($errorLog);

        $errClean = trim(preg_replace('/^.*?\[Warning\] Using a password on the command line interface can be insecure\..*?$/m', '', $errContent));

        if ($code !== 0 && !empty($errClean)) {
            throw new \RuntimeException("Gagal eksekusi SQL CLI: " . $errClean);
        }
    }
}
