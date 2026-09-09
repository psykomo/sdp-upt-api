<?php

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Repositories\QueueRepository;
use App\Modules\Shared\Support\UuidV7;

final class QueueService
{
    private QueueRepository $repository;

    public function __construct()
    {
        $this->repository = new QueueRepository();
    }

    /**
     * Push a new job into the queue.
     * 
     * @param string $type The job type
     * @param array<string, mixed> $payload The job data
     * @param int $maxAttempts Maximum number of retries
     * @param string|null $scheduledAt Optional date string to run the job at a specific time
     * @return string The generated Job ID (UUID v7)
     */
    public function push(?string $idUpt, string $type, array $payload, int $priority = 0, int $maxAttempts = 3, ?string $scheduledAt = null): string
    {
        $id = UuidV7::generate();
        $this->repository->insert($id, $idUpt, $type, $payload, $priority, $maxAttempts, $scheduledAt);
        return $id;
    }

    /**
     * Get the status, progress, and queue position of a specific job.
     * 
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function status(string $id): ?array
    {
        $row = $this->repository->getJobStatus($id);
        if ($row === null) {
            return null;
        }

        $row['queue_number_display']       = self::formatQueueNumber($row['QUEUE_NUMBER'] ?? null);
        $row['current_processing_display'] = self::formatQueueNumber($row['current_processing'] ?? null);

        return $row;
    }

    /**
     * Bulk status check: returns formatted job statuses + current processing info.
     *
     * @param string[] $jobIds
     * @return array{jobs: array, current_processing: string|null, current_processing_display: string|null}
     */
    public function bulkStatus(array $jobIds): array
    {
        $result = $this->repository->getStatusBulk($jobIds);

        // Format each job's queue number for display
        foreach ($result['jobs'] as &$job) {
            $job['queue_number_display'] = self::formatQueueNumber($job['QUEUE_NUMBER'] ?? null);
            // Decode RESULT JSON if present
            if (!empty($job['RESULT'])) {
                $decoded = json_decode($job['RESULT'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $job['progress'] = $decoded;
                }
                unset($job['RESULT']);
            }
        }
        unset($job);

        $result['current_processing_display'] = self::formatQueueNumber($result['current_processing']);

        return $result;
    }

    /**
     * Format QUEUE_NUMBER (e.g. "2026090805000001") into display: "000001 - 05 - 08092026"
     */
    public static function formatQueueNumber(?string $qn): ?string
    {
        if ($qn === null || strlen($qn) < 16) {
            return null;
        }
        // YYYYMMDD (8) + KANWIL (2) + SEQ (6)
        $datePart   = substr($qn, 0, 8);  // "20260908"
        $kanwilPart = substr($qn, 8, 2);  // "05"
        $seqPart    = substr($qn, 10, 6); // "000001"

        // Reformat date to DDMMYYYY
        $dateDisplay = substr($datePart, 6, 2) . substr($datePart, 4, 2) . substr($datePart, 0, 4);

        return $seqPart . ' - ' . $kanwilPart . ' - ' . $dateDisplay;
    }
}
