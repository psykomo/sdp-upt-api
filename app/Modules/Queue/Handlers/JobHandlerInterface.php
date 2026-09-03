<?php

namespace App\Modules\Queue\Handlers;

interface JobHandlerInterface
{
    /**
     * Handle the given queue job.
     * 
     * @param array<string, mixed> $payload The job data
     * @param string $jobId The ID of the job being processed
     * @return void
     * @throws \Exception If the job fails, throw an exception. The worker will catch it and mark the job as failed/retry.
     */
    public function handle(array $payload, string $jobId): void;
}
