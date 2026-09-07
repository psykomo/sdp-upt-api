<?php

namespace App\Commands;

use App\Modules\Queue\Services\QueueConsumer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class QueueWorkerCommand extends BaseCommand
{
    protected $group       = 'Queue';
    protected $name        = 'queue:work';
    protected $description = 'Run the queue worker to process pending jobs.';
    protected $usage       = 'queue:work [options]';
    protected $options     = [
        '--type'  => 'Filter by specific job type',
        '--once'  => 'Process only one job then exit',
        '--sleep' => 'Seconds to sleep when queue is empty (default: 2)',
    ];

    public function run(array $params)
    {
        // Set unlimited execution time and memory for background worker
        ini_set('memory_limit', '-1');
        set_time_limit(0);
        ignore_user_abort(true);

        $consumer = new QueueConsumer();
        
        $type = $params['type'] ?? CLI::getOption('type');
        $runOnce = array_key_exists('once', $params) || CLI::getOption('once');
        $sleepSeconds = (int) ($params['sleep'] ?? CLI::getOption('sleep') ?? env('queue.sleep', 2));
        
        CLI::write("Queue Worker started.", 'green');
        if ($type) {
            CLI::write("Filtering by type: {$type}", 'yellow');
        }

        $lastStaleReset = 0;
        
        while (true) {
            // Periodically reset stale jobs (e.g., every 5 minutes)
            if (time() - $lastStaleReset > 300) {
                $consumer->resetStale((int) env('queue.staleTimeout', 30));
                $lastStaleReset = time();
            }

            // Try to process a job
            $processed = $consumer->tick($type);
            
            if ($processed) {
                // Adaptive sleep: don't sleep if we just processed a job, 
                // there might be more waiting.
                if ($runOnce) {
                    CLI::write("Processed one job. Exiting due to --once flag.", 'green');
                    break;
                }
            } else {
                if ($runOnce) {
                    CLI::write("No jobs available. Exiting due to --once flag.", 'yellow');
                    break;
                }
                
                // Sleep when idle to reduce CPU/DB load
                sleep($sleepSeconds);
            }
        }
    }
}
