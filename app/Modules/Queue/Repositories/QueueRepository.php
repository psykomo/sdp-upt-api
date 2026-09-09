<?php

namespace App\Modules\Queue\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class QueueRepository
{
    private BaseConnection $db;

    public function __construct()
    {
        // Use the shared database connection
        $this->db = Database::connect();
    }

    /**
     * Insert a new job into the queue.
     *
     * @param string $id UUID v7
     * @param string $type The job type (e.g. 'konsolidasi_identitas')
     * @param array<string, mixed> $payload
     * @param int $maxAttempts
     * @param string|null $scheduledAt
     * @return bool
     */
    public function insert(string $id, ?string $idUpt, string $type, array $payload, int $priority = 0, int $maxAttempts = 3, ?string $scheduledAt = null): bool
    {
        $data = [
            'ID'           => $id,
            'ID_UPT'       => $idUpt,
            'TYPE'         => $type,
            'PAYLOAD'      => json_encode($payload),
            'PRIORITY'     => $priority,
            'MAX_ATTEMPTS' => $maxAttempts,
            'STATUS'       => 'pending',
            'VERSION'      => 0,
            'ATTEMPTS'     => 0,
        ];

        // Generate queue number for konsolidasi-type jobs when UPT is known
        $consolidationTypes = ['konsolidasi', 'data', 'dokumen', 'image', 'foto', 'sidik_jari', 'daf'];
        if ($idUpt !== null && (in_array(strtolower($type), $consolidationTypes) || strpos($type, 'konsolidasi') !== false)) {
            $data['QUEUE_NUMBER'] = $this->generateQueueNumber($idUpt);
        }

        if ($scheduledAt !== null) {
            $data['SCHEDULED_AT'] = $scheduledAt;
        }

        return $this->db->table('job_queue')->insert($data);
    }

    /**
     * Generate a queue number with pattern: YYYYMMDD{KANWIL_2DIGIT}{SEQ_6DIGIT}
     * Resets daily per-kanwil.
     *
     * Example: 2026090805000001 (2026-09-08, Kanwil 05, seq 1)
     */
    public function generateQueueNumber(string $idUpt): string
    {
        $today = date('Ymd');

        // Lookup kanwil code from upt table
        $upt = $this->db->table('upt')
            ->select('KANWIL')
            ->where('ID_UPT', $idUpt)
            ->get()
            ->getRowArray();

        $kanwil = $upt ? str_pad($upt['KANWIL'], 2, '0', STR_PAD_LEFT) : '00';

        // Prefix: date + kanwil (e.g. "2026090805")
        $prefix = $today . $kanwil;

        // Find max existing sequence for this prefix today
        // Uses LIKE prefix search which hits the UNIQUE index on QUEUE_NUMBER
        $row = $this->db->table('job_queue')
            ->selectMax('QUEUE_NUMBER', 'max_qn')
            ->like('QUEUE_NUMBER', $prefix, 'after')
            ->get()
            ->getRowArray();

        if (!empty($row['max_qn'])) {
            // Extract last 6 digits and increment
            $lastSeq = (int) substr($row['max_qn'], -6);
            $nextSeq = $lastSeq + 1;
        } else {
            $nextSeq = 1;
        }

        return $prefix . str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Bulk status check for multiple job IDs.
     * Returns job data + current processing info for queue position display.
     *
     * @param string[] $jobIds
     * @return array{jobs: array, current_processing: string|null}
     */
    public function getStatusBulk(array $jobIds): array
    {
        if (empty($jobIds)) {
            return ['jobs' => [], 'current_processing' => null];
        }

        // Fetch requested jobs
        $jobs = $this->db->table('job_queue')
            ->select('ID, ID_UPT, STATUS, QUEUE_NUMBER, RESULT, CREATED_AT, COMPLETED_AT')
            ->whereIn('ID', $jobIds)
            ->get()
            ->getResultArray();

        // Auto-assign queue number for any job that is missing it
        foreach ($jobs as &$j) {
            if (empty($j['QUEUE_NUMBER']) && !empty($j['ID_UPT'])) {
                $qn = $this->generateQueueNumber((string) $j['ID_UPT']);
                $this->db->table('job_queue')->where('ID', $j['ID'])->update(['QUEUE_NUMBER' => $qn]);
                $j['QUEUE_NUMBER'] = $qn;
            }
        }
        unset($j);

        // Find the queue number currently being processed (globally)
        $processing = $this->db->table('job_queue')
            ->select('QUEUE_NUMBER')
            ->where('STATUS', 'processing')
            ->where('QUEUE_NUMBER IS NOT NULL')
            ->orderBy('QUEUE_NUMBER', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $currentProcessing = $processing['QUEUE_NUMBER'] ?? null;

        // If nothing is processing, get the last completed/failed one
        if ($currentProcessing === null) {
            $lastDone = $this->db->table('job_queue')
                ->select('QUEUE_NUMBER')
                ->whereIn('STATUS', ['completed', 'failed'])
                ->where('QUEUE_NUMBER IS NOT NULL')
                ->orderBy('QUEUE_NUMBER', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();
            $currentProcessing = $lastDone['QUEUE_NUMBER'] ?? null;
        }

        return [
            'jobs'               => $jobs,
            'current_processing' => $currentProcessing,
        ];
    }

    /**
     * Poll for the next pending job.
     * Returns an array with the job data, or null if no job is available.
     * 
     * @param string|null $type Optional filter by job type
     * @return array<string, mixed>|null
     */
    public function pollNext(?string $type = null): ?array
    {
        $builder = $this->db->table('job_queue')
            ->where('STATUS', 'pending')
            ->where(new \CodeIgniter\Database\RawSql('SCHEDULED_AT <= NOW()'))
            ->orderBy('PRIORITY', 'DESC')
            ->orderBy('SCHEDULED_AT', 'ASC')
            ->orderBy('ID', 'ASC')
            ->limit(1);

        if ($type !== null) {
            $builder->where('TYPE', $type);
        }

        $row = $builder->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Attempt to claim a job using Compare-And-Swap (Optimistic Locking).
     * This ensures only one worker successfully claims a job.
     *
     * @param string $id
     * @param int $currentVersion
     * @return bool
     */
    public function claim(string $id, int $currentVersion): bool
    {
        $this->db->table('job_queue')
            ->where('ID', $id)
            ->where('VERSION', $currentVersion)
            ->where('STATUS', 'pending')
            ->update([
                'STATUS'    => 'processing',
                'VERSION'   => $currentVersion + 1,
                'LOCKED_AT' => new \CodeIgniter\Database\RawSql('NOW()'),
                'ATTEMPTS'  => new \CodeIgniter\Database\RawSql('ATTEMPTS + 1'),
            ]);

        // If affected rows is 1, the claim was successful.
        return $this->db->affectedRows() === 1;
    }

    /**
     * Mark a job as completed.
     */
    public function complete(string $id): void
    {
        $this->db->table('job_queue')
            ->where('ID', $id)
            ->update([
                'STATUS'       => 'completed',
                'COMPLETED_AT' => new \CodeIgniter\Database\RawSql('NOW()'),
            ]);
    }

    /**
     * Mark a job as failed, or reschedule it if max attempts have not been reached.
     */
    public function fail(string $id, int $attempts, int $maxAttempts, string $errorMessage): void
    {
        $isFinalFailure = $attempts >= $maxAttempts;
        
        $data = [
            'STATUS' => $isFinalFailure ? 'failed' : 'pending',
            'RESULT' => json_encode(['error' => $errorMessage]),
        ];

        // If retrying, delay the next attempt (exponential backoff)
        if (!$isFinalFailure) {
            // Delay: attempts * 30 seconds
            $delaySeconds = $attempts * 30;
            $data['SCHEDULED_AT'] = new \CodeIgniter\Database\RawSql("NOW() + INTERVAL {$delaySeconds} SECOND");
        }

        $this->db->table('job_queue')
            ->where('ID', $id)
            ->update($data);
    }

    /**
     * Update the progress of a processing job.
     * 
     * @param string $id
     * @param int $percent
     * @param string $message
     */
    public function updateProgress(string $id, int $percent, string $message): void
    {
        $progressJson = json_encode([
            'percent' => $percent,
            'message' => $message
        ]);

        $this->db->table('job_queue')
            ->where('ID', $id)
            ->update([
                'RESULT'    => $progressJson,
                'LOCKED_AT' => new \CodeIgniter\Database\RawSql('NOW()'), // Heartbeat: refresh lock timestamp so long-running jobs are not marked stale
            ]);
    }

    /**
     * Get the job status, progress, and queue position.
     */
    public function getJobStatus(string $id): ?array
    {
        $row = $this->db->table('job_queue')
            ->select('ID, ID_UPT, TYPE, STATUS, RESULT, QUEUE_NUMBER, CREATED_AT, COMPLETED_AT')
            ->where('ID', $id)
            ->get()
            ->getRowArray();
            
        if (!$row) return null;

        // Auto-assign queue number if missing
        if (empty($row['QUEUE_NUMBER']) && !empty($row['ID_UPT'])) {
            $qn = $this->generateQueueNumber((string) $row['ID_UPT']);
            $this->db->table('job_queue')->where('ID', $row['ID'])->update(['QUEUE_NUMBER' => $qn]);
            $row['QUEUE_NUMBER'] = $qn;
        }
        
        // Decode RESULT JSON into progress sub-object
        if (!empty($row['RESULT'])) {
            $decoded = json_decode($row['RESULT'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['progress'] = $decoded;
            }
            unset($row['RESULT']);
        }

        // Find the queue number currently being processed (globally)
        $processing = $this->db->table('job_queue')
            ->select('QUEUE_NUMBER')
            ->where('STATUS', 'processing')
            ->where('QUEUE_NUMBER IS NOT NULL')
            ->orderBy('QUEUE_NUMBER', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $row['current_processing'] = $processing['QUEUE_NUMBER'] ?? null;
        
        return $row;
    }

    /**
     * Reset jobs that have been 'processing' for too long (crashed workers).
     * 
     * @param int $timeoutMinutes
     * @return int Number of jobs reset
     */
    public function resetStale(int $timeoutMinutes = 30): int
    {
        $staleMinutes = (int) $timeoutMinutes;

        $this->db->table('job_queue')
            ->where('STATUS', 'processing')
            ->where(new \CodeIgniter\Database\RawSql("LOCKED_AT < NOW() - INTERVAL {$staleMinutes} MINUTE"))
            ->update([
                'STATUS' => 'pending',
                'RESULT' => json_encode(['error' => 'Job stalled and was automatically reset.'])
            ]);

        return $this->db->affectedRows();
    }

    /**
     * Cleanup completed jobs older than given days.
     * 
     * @param int $olderThanDays
     * @return int Number of jobs deleted
     */
    public function cleanupCompleted(int $olderThanDays = 30): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$olderThanDays} days"));

        $this->db->table('job_queue')
            ->where('STATUS', 'completed')
            ->where('COMPLETED_AT <', $cutoffDate)
            ->delete();

        return $this->db->affectedRows();
    }
}
