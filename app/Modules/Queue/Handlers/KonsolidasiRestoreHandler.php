<?php

namespace App\Modules\Queue\Handlers;

use App\Modules\Queue\Repositories\QueueRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Exception;
use ZipArchive;

final class KonsolidasiRestoreHandler implements JobHandlerInterface
{
    private QueueRepository $repository;
    private BaseConnection $db;

    // Temporary storage for parsed ini configs
    private ?array $tablesFields = null;
    private ?array $tablesInfo = null;

    public function __construct()
    {
        $this->repository = new QueueRepository();
        $this->db = Database::connect(); // Using shared_database
    }

    public function handle(array $payload, string $jobId): void
    {
        $filePath = $payload['file_path'] ?? '';
        $satkerId = $payload['satker_id'] ?? '';
        $dataType = $payload['data_type'] ?? 'data';

        if (empty($filePath) || !file_exists($filePath)) {
            throw new \RuntimeException("Zip file not found at: {$filePath}");
        }

        $this->repository->updateProgress($jobId, 5, 'Mengekstrak file ZIP...');
        $restoreTempPath = $this->extractZip($filePath);

        try {
            $this->repository->updateProgress($jobId, 10, 'Membaca konfigurasi tabel...');
            
            // Parse info.ini and tables_fields.ini
            $this->loadIniConfigs($restoreTempPath);

            if (empty($this->tablesFields)) {
                throw new \RuntimeException("Konfigurasi tables_fields.ini tidak ditemukan atau kosong.");
            }

            // Determine if sender is UPT or Kanwil
            $isFromUpt = $this->isFromUpt($satkerId);

            $tables = array_keys($this->tablesFields);
            $total = count($tables);

            foreach ($tables as $index => $tableName) {
                $pct = 15 + (int) ((($index + 1) / $total) * 80);
                $this->repository->updateProgress($jobId, $pct, "Memproses tabel: {$tableName}...");

                $csvFileName = "{$tableName}.csv";
                $this->restoreFileToTable($restoreTempPath, $csvFileName, $tableName, $dataType, $satkerId, $isFromUpt);
            }

            // TODO: Post-import side effects (Recalculate ekspirasi, integrasi remisi, dsb)

            $this->repository->updateProgress($jobId, 100, 'Konsolidasi selesai.');
        } finally {
            $this->cleanupTemp($restoreTempPath);
        }
    }

    private function extractZip(string $filePath): string
    {
        $tempPath = WRITEPATH . 'temp/restore_' . uniqid();
        if (!is_dir($tempPath)) {
            mkdir($tempPath, 0777, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) === TRUE) {
            $zip->extractTo($tempPath);
            $zip->close();
        } else {
            throw new \RuntimeException("Gagal membuka file ZIP: {$filePath}");
        }

        return $tempPath;
    }

    private function loadIniConfigs(string $path): void
    {
        $fieldsFile = $path . '/tables_fields.ini';
        if (file_exists($fieldsFile)) {
            $this->tablesFields = parse_ini_file($fieldsFile, true);
        }

        $infoFile = $path . '/tables_info.ini';
        if (file_exists($infoFile)) {
            $this->tablesInfo = parse_ini_file($infoFile, true);
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
        $this->validateAccessControl($tableName, $isFromUpt);

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
        $tempTableName = "tmp_{$tableName}_" . bin2hex(random_bytes(4));
        $this->db->query("DROP TEMPORARY TABLE IF EXISTS `{$tempTableName}`");
        
        // Buat tabel temporary persis seperti tabel asli (untuk kemudahan)
        $this->db->query("CREATE TEMPORARY TABLE `{$tempTableName}` LIKE `{$tableName}`");

        // 5. LOAD DATA LOCAL INFILE ke Temporary Table
        $escapedPath = str_replace('\\', '/', $csvFileAddress);
        $sqlLoadTemp = "
            LOAD DATA LOCAL INFILE '{$escapedPath}'
            INTO TABLE `{$tempTableName}`
            FIELDS TERMINATED BY ','
            ENCLOSED BY '\"'
            LINES TERMINATED BY '\r\n'
            ({$fieldsStr})
        ";
        $this->db->query($sqlLoadTemp);

        // 6. Validasi Data Khusus (Pusat Only)
        $isValidated = false;

        if ($tableName === 'integrasi' || $tableName === 'integrasi_pidana' || $tableName === 'integrasi_remisi') {
            // Blokir data yang sudah diedit Ditjen/Kanwil
            $whereEdit = "inte.IS_DITJEN_EDIT = 1";
            if ($isFromUpt) {
                $whereEdit .= " OR inte.IS_KANWIL_EDIT = 1";
            }
            
            $integField = $tableName === 'integrasi' ? 'ID_INTEGRASI' : ($tableName === 'integrasi_pidana' ? 'ID_INTEGRASI_PIDANA' : 'ID_INTEGRASI_REMISI'); // Sederhananya pakai field_key

            $sqlBlockInteg = "
                DELETE tmp FROM `{$tempTableName}` tmp
                INNER JOIN `integrasi` inte ON inte.{$integField} = tmp.{$fieldKey}
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

            // Actual Replace
            $setFlags = [];
            if (isset($localFields['KONSOLIDASI'])) $setFlags[] = "`KONSOLIDASI` = 2";
            if (isset($localFields['KONSOLIDASI_IMAGE'])) $setFlags[] = "`KONSOLIDASI_IMAGE` = 2";
            
            $setStr = count($setFlags) > 0 ? "SET " . implode(", ", $setFlags) : "";

            $sqlReplace = "
                LOAD DATA LOCAL INFILE '{$escapedPath}'
                REPLACE INTO TABLE `{$tableName}`
                FIELDS TERMINATED BY ','
                ENCLOSED BY '\"'
                LINES TERMINATED BY '\r\n'
                ({$fieldsStr})
                {$setStr}
            ";
            $this->db->query($sqlReplace);
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        $this->db->query("DROP TEMPORARY TABLE IF EXISTS `{$tempTableName}`");
    }

    private function validateAccessControl(string $tableName, bool $isFromUpt): void
    {
        // Rule: Milik Ditjen, tidak boleh dari UPT maupun Kanwil
        if (in_array($tableName, [
            'integrasi_sidang_ditjen', 'integrasi_sk_ditjen', 'integrasi_persetujuan_ditjen', 'integrasi_persetujuan_ditjen_hasil',
            'sidang_tpp_ditjen', 'sidang_tpp_ditjen_usulan', 'remisi_sk_ditjen', 'persetujuan', 'persetujuan_detail',
            'verifikasi', 'verifikasi_history'
        ])) {
            throw new \RuntimeException("Data [{$tableName}] milik pusat tidak dapat diakses.");
        }

        if ($isFromUpt) {
            // Rule: Milik Kanwil, tidak boleh dari UPT
            if (in_array($tableName, [
                'integrasi_sidang_kanwil', 'integrasi_sidang_kanwil_hasil', 'integrasi_sk_kanwil', 'integrasi_penetapan_persetujuan_kanwil',
                'sidang_tpp_kanwil', 'sidang_tpp_kanwil_usulan', 'remisi_sk_kanwil', 'remisi_otorisasi_sk'
            ])) {
                throw new \RuntimeException("Data [{$tableName}] milik kanwil tidak dapat diakses oleh UPT.");
            }
        } else {
            // Rule: Milik UPT, tidak boleh dari Kanwil
            if (in_array($tableName, [
                'integrasi_sidang_upt', 'integrasi_sidang_upt_hasil', 'sidang_tpp_upt', 'sidang_tpp_upt_usulan'
            ])) {
                throw new \RuntimeException("Data [{$tableName}] milik UPT tidak dapat diakses oleh Kanwil.");
            }
            
            // Allowlist untuk Kanwil: hanya integrasi, sidangtpp, dan dokumen
            if (strpos($tableName, 'integrasi') !== 0 && strpos($tableName, 'sidang_tpp') !== 0 && strpos($tableName, 'dokumen') !== 0) {
                throw new \RuntimeException("Data [{$tableName}] bukan bagian dari integrasi/sidang tpp tidak dapat diakses oleh Kanwil.");
            }
        }
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
}
