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
    public function push(string $type, array $payload, int $maxAttempts = 3, ?string $scheduledAt = null): string
    {
        $id = UuidV7::generate();
        $this->repository->insert($id, $type, $payload, $maxAttempts, $scheduledAt);
        return $id;
    }

    /**
     * Get the status and progress of a specific job.
     * 
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function status(string $id): ?array
    {
        return $this->repository->getJobStatus($id);
    }
}
