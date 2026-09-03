<?php

namespace App\Commands;

use App\Modules\Queue\Services\QueueConsumer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class QueueCleanupCommand extends BaseCommand
{
    protected $group       = 'Queue';
    protected $name        = 'queue:cleanup';
    protected $description = 'Cleanup completed queue jobs older than specified days.';
    protected $usage       = 'queue:cleanup [options]';
    protected $options     = [
        '--days' => 'Number of days to keep completed jobs (default: 30)',
    ];

    public function run(array $params)
    {
        $days = (int) ($params['days'] ?? CLI::getOption('days') ?? 30);
        
        CLI::write("Cleaning up completed jobs older than {$days} days...", 'yellow');
        
        $consumer = new QueueConsumer();
        $deleted = $consumer->cleanup($days);
        
        CLI::write("Cleanup complete. Deleted {$deleted} jobs.", 'green');
    }
}
