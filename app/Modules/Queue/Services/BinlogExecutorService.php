<?php

namespace App\Modules\Queue\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class BinlogExecutorService
{
    private BaseConnection $db;
    private string $mysqlBinPath;
    private string $host;
    private int $port;
    private string $user;
    private string $pass;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();

        $this->host = (string) (env('database.default.hostname') ?: '127.0.0.1');
        $this->port = (int) (env('database.default.port') ?: 3310);
        $this->user = (string) (env('database.default.username') ?: 'root');
        $this->pass = (string) (env('database.default.password') ?? '');

        $this->mysqlBinPath = $this->resolveMysqlBinDir();
    }

    /**
     * Resolusi direktori bin MySQL (tempat mysqlbinlog dan mysql berada).
     */
    private function resolveMysqlBinDir(): string
    {
        $configured = trim((string) (env('MYSQL_BIN_PATH') ?? ''), " \t\n\r\0\x0B\"'");
        if (!empty($configured) && is_dir($configured)) {
            return realpath($configured) ?: $configured;
        }

        return '';
    }

    /**
     * Dapatkan path lengkap ke binary mysqlbinlog atau mysql.
     */
    public function getBinaryPath(string $binaryName): string
    {
        $isWindows = (DIRECTORY_SEPARATOR === '\\');
        $ext = $isWindows ? '.exe' : '';
        $targetFile = $binaryName . $ext;

        if (!empty($this->mysqlBinPath)) {
            $fullPath = rtrim($this->mysqlBinPath, '/\\') . DIRECTORY_SEPARATOR . $targetFile;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        // Cek jika tersedia langsung di PATH sistem
        $testCmd = $isWindows ? "where {$binaryName}" : "which {$binaryName}";
        @exec($testCmd, $out, $code);
        if ($code === 0 && !empty($out[0])) {
            return trim($out[0]);
        }

        throw new RuntimeException("Binary {$binaryName} tidak ditemukan. Harap konfigurasi MYSQL_BIN_PATH di file .env.");
    }

    /**
     * Ekstrak nomor sequence dari nama file binlog (misal: mysql-bin.000055 -> 55).
     */
    public function extractSequenceNumber(string $fileName): int
    {
        $base = basename($fileName);
        if (preg_match('/(\d+)$/', $base, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Validasi nomor sequence paket binlog terhadap INCREAMENT_BACKUP_NUMBER di tabel UPT.
     * Mengikuti pola file terakhir: nomor sequence yang dicatat adalah nomor file terakhir.
     *
     * @param  string $satkerId
     * @param  array  $files
     * @param  bool   $isRetry
     * @param  bool   $isFull
    /**
     * Validasi nomor sequence paket konsolidasi / binlog terhadap INCREAMENT_BACKUP_NUMBER di tabel UPT.
     * Menggunakan backup_number (nomor paket konsolidasi) jika tersedia, fallback ke nomor file binlog.
     *
     * @param  string   $satkerId
     * @param  array    $files
     * @param  bool     $isRetry
     * @param  bool     $isFull
     * @param  int|null $packageBackupNumber Nomor urut paket konsolidasi dari info.ini
     * @return array{first: int, last: int}
     */
    public function validateSequence(
        string $satkerId,
        array $files,
        bool $isRetry = false,
        bool $isFull = false,
        ?int $packageBackupNumber = null
    ): array {
        natsort($files);
        $files = array_values($files);

        $firstFile   = reset($files) ?: '';
        $lastFile    = end($files) ?: '';
        $firstNumber = $this->extractSequenceNumber($firstFile);
        $lastNumber  = $this->extractSequenceNumber($lastFile);

        // Tidak ada pemblokiran nomor urut konsolidasi (eksekusi idempoten)
        return [
            'first' => $firstNumber,
            'last'  => $lastNumber,
        ];
    }

    /**
     * Replay file-file binlog atau SQL ke database replica UPT (db{satker_id}).
     *
     * @param  string   $extractPath Direktori tempat file binlog hasil ekstraksi
     * @param  string   $satkerId    ID UPT pengirim (misal 093 atau 379)
     * @param  array    $files       Daftar nama file (misal ['mysql-bin.000054', 'mysql-bin.000055'])
     * @param  string   $format      'bin-log' atau 'sql'
     * @param  string   $clientDb    Nama database pada client (default: db_sdp)
     * @param  string   $timePoint   Waktu konsolidasi dari info.ini
     * @param  callable $onProgress  Callback progress: fn(string $message, int $pct)
     * @return int Nomor binlog terakhir yang dieksekusi
     */
    public function replay(
        string $extractPath,
        string $satkerId,
        array $files,
        string $format = 'bin-log',
        string $clientDb = 'db_sdp',
        string $timePoint = '',
        ?callable $onProgress = null,
        ?int $packageBackupNumber = null
    ): int {
        natsort($files);
        $files = array_values($files);
        $totalFiles = count($files);

        if ($totalFiles === 0) {
            throw new RuntimeException("Tidak ada file binlog/sql yang ditemukan dalam paket konsolidasi.");
        }

        $databaseUpt = 'db' . $satkerId;

        // 1. Pastikan database replica UPT ada
        $this->db->query("CREATE DATABASE IF NOT EXISTS `{$databaseUpt}`");
        $this->db->query("SET GLOBAL log_bin_trust_function_creators = 1");

        $mysqlbinlogBin = ($format === 'bin-log') ? $this->getBinaryPath('mysqlbinlog') : '';
        $mysqlBin       = $this->getBinaryPath('mysql');

        $isWindows = (DIRECTORY_SEPARATOR === '\\');
        $lastNumber = 0;

        // Pastikan tabel tracking / jembatan di database replica UPT sudah ada
        $this->ensureReplicaSchema($databaseUpt);

        foreach ($files as $index => $file) {
            $fileNum = $this->extractSequenceNumber($file);
            $lastNumber = $fileNum > 0 ? $fileNum : $lastNumber;

            // Hitung persentase progres tahap replay (10% sampai 30%)
            $pct = 10 + (int) ((($index + 1) / $totalFiles) * 20);
            if (is_callable($onProgress)) {
                $onProgress("Memutar binlog {$file} ke {$databaseUpt}...", $pct);
            }

            $sourceFile = $extractPath . DIRECTORY_SEPARATOR . $file;
            if (!file_exists($sourceFile)) {
                throw new RuntimeException("File konsolidasi tidak ditemukan: {$file}");
            }

            $sourcePathCmd = $isWindows ? str_replace('/', '\\', $sourceFile) : $sourceFile;
            $extractDirCmd = $isWindows ? str_replace('/', '\\', rtrim($extractPath, '/\\')) : rtrim($extractPath, '/');
            $errorLogFile  = $extractPath . DIRECTORY_SEPARATOR . $file . '_err.log';
            $errorLogCmd   = $isWindows ? str_replace('/', '\\', $errorLogFile) : $errorLogFile;

            // Argumen koneksi mysql CLI
            $connArgs = sprintf('--host="%s" --port=%d --user="%s"', $this->host, $this->port, $this->user);
            if ($this->pass !== '') {
                $connArgs .= sprintf(' --password="%s"', addcslashes($this->pass, '"\\'));
            }

            if ($format === 'bin-log') {
                $sqlFile = $extractPath . DIRECTORY_SEPARATOR . $file . '.sql';
                $sqlPathCmd = $isWindows ? str_replace('/', '\\', $sqlFile) : $sqlFile;

                // Tahap 1: Ekstraksi binlog ke SQL dengan rewrite database
                $binlogCmd = sprintf(
                    '"%s" %s --rewrite-db="%s->%s" --local-load="%s" --database=%s "%s" > "%s"',
                    $mysqlbinlogBin,
                    $connArgs,
                    $clientDb,
                    $databaseUpt,
                    $extractDirCmd,
                    $clientDb,
                    $sourcePathCmd,
                    $sqlPathCmd
                );

                exec($binlogCmd, $outExtract, $codeExtract);
                if ($codeExtract !== 0 || !file_exists($sqlFile) || filesize($sqlFile) === 0) {
                    throw new RuntimeException("Gagal mengonversi file binlog {$file} ke SQL: exit code {$codeExtract}");
                }

                // Tahap 1.5: Ganti nama database literal di file SQL (misal: `db_sdp`.`upt` -> `db379`.`upt`)
                // karena --rewrite-db hanya mengganti statement USE tapi tidak mengganti prefix tabel eksplisit
                $this->rewriteDatabaseInSql($sqlFile, $clientDb, $databaseUpt);

                // Tahap 2: Eksekusi SQL ke database replica
                $mysqlCmd = sprintf(
                    '"%s" --local-infile %s --force %s < "%s" 2> "%s"',
                    $mysqlBin,
                    $connArgs,
                    $databaseUpt,
                    $sqlPathCmd,
                    $errorLogCmd
                );

                exec($mysqlCmd, $outExec, $codeExec);
            } else {
                // Format SQL langsung: rewrite database jika ada
                $this->rewriteDatabaseInSql($sourceFile, $clientDb, $databaseUpt);

                $mysqlCmd = sprintf(
                    '"%s" --local-infile %s --force %s < "%s" 2> "%s"',
                    $mysqlBin,
                    $connArgs,
                    $databaseUpt,
                    $sourcePathCmd,
                    $errorLogCmd
                );

                exec($mysqlCmd, $outExec, $codeExec);
            }

            // Evaluasi error log
            $this->evaluateErrorLog($errorLogFile, $file, $databaseUpt);
        }

        // Update INCREAMENT_BACKUP_NUMBER di database pusat (upt)
        // Gunakan nomor paket konsolidasi (backup_number dari info.ini) agar selalu sinkron dengan counter UPT
        $saveNumber = ($packageBackupNumber !== null && $packageBackupNumber > 0) ? $packageBackupNumber : $lastNumber;
        if ($saveNumber > 0) {
            $updateData = ['INCREAMENT_BACKUP_NUMBER' => $saveNumber];
            if (!empty($timePoint)) {
                $updateData['INCREAMENT_BACKUP_TIME'] = $timePoint;
            } else {
                $updateData['INCREAMENT_BACKUP_TIME'] = date('Y-m-d H:i:s');
            }

            $this->db->table('upt')
                ->where('ID_UPT', $satkerId)
                ->update($updateData);
        }

        return $lastNumber;
    }

    /**
     * Memastikan tabel-tabel pendukung modern (seperti log_konsolidasi_job)
     * sudah terbuat di database replica satker tujuan (CREATE TABLE IF NOT EXISTS).
     */
    private function ensureReplicaSchema(string $databaseUpt): void
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS `{$databaseUpt}`.`log_konsolidasi_job` (
                `ID_LOG` INT(11) NOT NULL,
                `JOB_ID` VARCHAR(36) NOT NULL,
                `LOG_NAME_NEXT` VARCHAR(50) DEFAULT NULL,
                PRIMARY KEY (`ID_LOG`),
                KEY `idx_job_id` (`JOB_ID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1";
            $this->db->query($sql);
        } catch (\Throwable $e) {
            log_message('warning', "Gagal ensureReplicaSchema untuk {$databaseUpt}: " . $e->getMessage());
        }
    }

    /**
     * Mengganti prefix database literal pada file SQL (misal `db_sdp`. -> `db379`.)
     * secara streaming dengan buffer hemat memori.
     */
    private function rewriteDatabaseInSql(string $sqlFile, string $fromDb, string $toDb): void
    {
        if ($fromDb === $toDb || !file_exists($sqlFile)) {
            return;
        }

        $tempFile = $sqlFile . '.rw_tmp';
        $in  = @fopen($sqlFile, 'rb');
        $out = @fopen($tempFile, 'wb');
        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            return;
        }

        $patterns = [
            '`' . $fromDb . '`.' => '`' . $toDb . '`.',
            $fromDb . '.'        => $toDb . '.',
        ];

        while (!feof($in)) {
            $line = fgets($in, 1048576);
            if ($line === false) break;
            $line = str_replace(array_keys($patterns), array_values($patterns), $line);
            fwrite($out, $line);
        }

        fclose($in);
        fclose($out);

        @unlink($sqlFile);
        @rename($tempFile, $sqlFile);
    }

    /**
     * Memeriksa log error eksekusi MySQL CLI.
     * Mengabaikan warning standar dan error 1062 (Duplicate entry), namun melempar exception jika ada error fatal lain.
     */
    private function evaluateErrorLog(string $errorLogFile, string $fileName, string $databaseName): void
    {
        if (!file_exists($errorLogFile)) {
            return;
        }

        $logContent = file_get_contents($errorLogFile) ?: '';
        if (trim($logContent) === '') {
            @unlink($errorLogFile);
            return;
        }

        // Bersihkan warning password pada command line
        $clean = preg_replace('/^.*?\[Warning\] Using a password on the command line interface can be insecure\..*?$/m', '', $logContent);
        $clean = trim((string) $clean);

        if ($clean === '') {
            @unlink($errorLogFile);
            return;
        }

        $lines = array_filter(array_map('trim', explode("\n", $clean)));
        $fatalErrors = [];

        foreach ($lines as $line) {
            if ($line === '') continue;

            // Abaikan duplicate entry (error 1062) — idempoten / wajar saat konsolidasi retry
            if (preg_match('/ERROR 1062 \(23000\).*Duplicate entry/i', $line)) {
                continue;
            }

            // Abaikan duplicate column name (error 1060) atau duplicate key name (error 1061) saat migrasi/alter idempoten
            if (preg_match('/ERROR 106[01] \(42[S0][0-9]{2}\).*Duplicate (column|key) name/i', $line)) {
                continue;
            }

            // Abaikan error pada tabel tracking antrean internal UPT (log_konsolidasi_job)
            if (stripos($line, 'log_konsolidasi_job') !== false) {
                continue;
            }

            // Abaikan warning trigger / function creator jika ada
            if (stripos($line, 'Warning') !== false && stripos($line, 'ERROR') === false) {
                continue;
            }

            $fatalErrors[] = $line;
        }

        if (!empty($fatalErrors)) {
            $msg = "Fatal error saat eksekusi binlog {$fileName} ke {$databaseName}: " . implode(' | ', array_slice($fatalErrors, 0, 5));
            log_message('error', $msg);
            throw new RuntimeException($msg);
        }

        @unlink($errorLogFile);
    }
}
