<?php

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Repositories\QueueRepository;
use App\Modules\Queue\Handlers\JobHandlerInterface;
use App\Modules\Queue\Handlers\KonsolidasiRestoreHandler;

final class QueueConsumer
{
    private QueueRepository $repository;

    public function __construct()
    {
        $this->repository = new QueueRepository();
    }

    /**
     * Run one tick of the worker loop (poll one job, claim, execute).
     * 
     * @param string|null $type Optional type filter
     * @return bool True if a job was processed, false if the queue was empty.
     */
    public function tick(?string $type = null): bool
    {
        // 1. Poll for candidate job
        $job = $this->repository->pollNext($type);
        if (!$job) {
            return false;
        }

        // 2. Try to claim it using CAS
        $claimed = $this->repository->claim($job['id'], (int) $job['version']);
        if (!$claimed) {
            // We lost the race to another worker. Just return and try again next tick.
            return false;
        }

        // 3. We own the job. Process it.
        $payload = json_decode($job['payload'], true);
        
        try {
            $handler = $this->resolveHandler($job['type']);
            if (!$handler) {
                throw new \RuntimeException("No handler registered for job type: {$job['type']}");
            }

            // Execute the handler
            $handler->handle($payload, $job['id']);

            // 4a. Mark as completed
            $this->repository->complete($job['id']);
        } catch (\Throwable $e) {
            // 4b. Handle failure
            $attempts = (int) $job['attempts'] + 1; // +1 because claim() increments it in DB, but $job contains old value
            $maxAttempts = (int) $job['max_attempts'];
            $this->repository->fail($job['id'], $attempts, $maxAttempts, $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        return true;
    }

    /**
     * Reset stale jobs that crashed mid-processing.
     */
    public function resetStale(int $timeoutMinutes = 30): int
    {
        return $this->repository->resetStale($timeoutMinutes);
    }

    /**
     * Cleanup completed jobs.
     */
    public function cleanup(int $olderThanDays = 30): int
    {
        return $this->repository->cleanupCompleted($olderThanDays);
    }

    /**
     * Resolve the job handler class based on the job type.
     */
    private function resolveHandler(string $type): ?JobHandlerInterface
    {
        return match ($type) {
            'konsolidasi' => new KonsolidasiRestoreHandler(),
            // add more handlers here
            default => null,
        };
    }
}
