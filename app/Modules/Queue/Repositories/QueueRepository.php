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
    public function insert(string $id, string $type, array $payload, int $maxAttempts = 3, ?string $scheduledAt = null): bool
    {
        $data = [
            'id'           => $id,
            'type'         => $type,
            'payload'      => json_encode($payload),
            'max_attempts' => $maxAttempts,
            'status'       => 'pending',
            'version'      => 0,
            'attempts'     => 0,
        ];

        if ($scheduledAt !== null) {
            $data['scheduled_at'] = $scheduledAt;
        }

        return $this->db->table('job_queue')->insert($data);
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
            ->where('status', 'pending')
            ->where('scheduled_at <=', date('Y-m-d H:i:s'))
            ->orderBy('scheduled_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(1);

        if ($type !== null) {
            $builder->where('type', $type);
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
            ->where('id', $id)
            ->where('version', $currentVersion)
            ->where('status', 'pending')
            ->update([
                'status'    => 'processing',
                'version'   => $currentVersion + 1,
                'locked_at' => date('Y-m-d H:i:s'),
                'attempts'  => new \CodeIgniter\Database\RawSql('attempts + 1'),
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
            ->where('id', $id)
            ->update([
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Mark a job as failed, or reschedule it if max attempts have not been reached.
     */
    public function fail(string $id, int $attempts, int $maxAttempts, string $errorMessage): void
    {
        $isFinalFailure = $attempts >= $maxAttempts;
        
        $data = [
            'status' => $isFinalFailure ? 'failed' : 'pending',
            'result' => json_encode(['error' => $errorMessage]),
        ];

        // If retrying, delay the next attempt (exponential backoff)
        if (!$isFinalFailure) {
            // Delay: attempts * 30 seconds
            $delaySeconds = $attempts * 30;
            $data['scheduled_at'] = date('Y-m-d H:i:s', time() + $delaySeconds);
        }

        $this->db->table('job_queue')
            ->where('id', $id)
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
            ->where('id', $id)
            ->update(['result' => $progressJson]);
    }

    /**
     * Get the job status and progress.
     */
    public function getJobStatus(string $id): ?array
    {
        $row = $this->db->table('job_queue')
            ->select('id, type, status, result, created_at')
            ->where('id', $id)
            ->get()
            ->getRowArray();
            
        if (!$row) return null;
        
        // Decode result to array if it exists and is JSON
        if (!empty($row['result'])) {
            $decoded = json_decode($row['result'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['progress'] = $decoded;
            }
            unset($row['result']);
        }
        
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
        $staleThreshold = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));

        $this->db->table('job_queue')
            ->where('status', 'processing')
            ->where('locked_at <', $staleThreshold)
            ->update([
                'status' => 'pending',
                'result' => json_encode(['error' => 'Job stalled and was automatically reset.'])
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
            ->where('status', 'completed')
            ->where('completed_at <', $cutoffDate)
            ->delete();

        return $this->db->affectedRows();
    }
}
