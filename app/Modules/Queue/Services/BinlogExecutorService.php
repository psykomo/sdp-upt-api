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

        // Fallback lokasi standar jika ada
        $candidates = [
            'D:/_development/database/mysql/bin',
            'C:/xampp/mysql/bin',
            'C:/laragon/bin/mysql/current/bin',
            '/usr/bin',
            '/usr/local/bin',
        ];

        foreach ($candidates as $cand) {
            if (is_dir($cand)) {
                return realpath($cand) ?: $cand;
            }
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
     * @return array{first: int, last: int}
     */
    public function validateSequence(string $satkerId, array $files, bool $isRetry = false, bool $isFull = false): array
    {
        natsort($files);
        $files = array_values($files);

        $firstFile   = reset($files);
        $lastFile    = end($files);
        $firstNumber = $this->extractSequenceNumber($firstFile);
        $lastNumber  = $this->extractSequenceNumber($lastFile);

        $uptRow = $this->db->table('upt')
            ->select('INCREAMENT_BACKUP_NUMBER')
            ->where('ID_UPT', $satkerId)
            ->get()
            ->getRow();

        $currentNumber = $uptRow ? (int) $uptRow->INCREAMENT_BACKUP_NUMBER : 0;

        if (!$isFull && !$isRetry && $currentNumber > 0) {
            // Jika file yang dikirim nomornya sudah lebih kecil atau sama dengan yang ada di pusat
            if ($lastNumber <= $currentNumber) {
                throw new RuntimeException("File binlog #{$lastNumber} sudah pernah diproses sebelumnya (Nomor urut UPT saat ini #{$currentNumber}).");
            }

            // Peringatkan jika nomor urut melompat (gap)
            if ($firstNumber > ($currentNumber + 1)) {
                log_message('warning', "UPT {$satkerId}: Terjadi lompatan nomor binlog dari #{$currentNumber} langsung ke #{$firstNumber}.");
            }
        }

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
        ?callable $onProgress = null
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
                // Format SQL langsung
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
        if ($lastNumber > 0) {
            $updateData = ['INCREAMENT_BACKUP_NUMBER' => $lastNumber];
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
