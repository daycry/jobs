<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Queues.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Daycry\Jobs\Libraries\RateLimiter;
use Daycry\Jobs\Models\JobsLogModel;
use Daycry\Jobs\Models\QueueModel;

/**
 * Health check command for monitoring queue system status.
 * Provides statistics about queues, workers, and job processing.
 */
class HealthCheckCommand extends BaseCommand
{
    protected $group       = 'Jobs';
    protected $name        = 'jobs:health';
    protected $description = 'Display health check and statistics for the Jobs system';
    protected $usage       = 'jobs:health [options]';
    protected $arguments   = [];
    protected $options     = [
        '--json'  => 'Output as JSON',
        '--queue' => 'Check specific queue',
    ];

    public function run(array $params): void
    {
        $json          = CLI::getOption('json');
        $specificQueue = CLI::getOption('queue');

        $stats = $this->collectStats($specificQueue);

        if ($json) {
            CLI::write(json_encode($stats, JSON_PRETTY_PRINT));
        } else {
            $this->displayStats($stats);
        }
    }

    private function collectStats(?string $specificQueue = null): array
    {
        $config      = config('Jobs');
        $queueModel  = new QueueModel();
        $logModel    = model(JobsLogModel::class);
        $rateLimiter = new RateLimiter();

        $stats = [
            'timestamp' => date('Y-m-d H:i:s'),
            'config'    => [
                'log_performance'   => $config->logPerformance,
                'retry_strategy'    => $config->retryBackoffStrategy,
                'job_timeout'       => $config->jobTimeout,
                'dead_letter_queue' => $config->deadLetterQueue ?? 'disabled',
                'batch_size'        => $config->batchSize,
            ],
            'queues' => [],
        ];

        // Get queue statistics
        $queues = is_array($config->queues) ? $config->queues : explode(',', $config->queues);

        foreach ($queues as $queue) {
            $queue = trim($queue);

            if ($specificQueue && $queue !== $specificQueue) {
                continue;
            }

            $queueStats = [
                'name'    => $queue,
                'pending' => $queueModel->where('queue', $queue)
                    ->where('status', 'pending')
                    ->countAllResults(false),
                'processing' => $queueModel->where('queue', $queue)
                    ->where('status', 'processing')
                    ->countAllResults(false),
                'completed' => $queueModel->where('queue', $queue)
                    ->where('status', 'completed')
                    ->countAllResults(false),
                'failed' => $queueModel->where('queue', $queue)
                    ->where('status', 'failed')
                    ->countAllResults(false),
            ];

            // Rate limit usage
            $rateLimit = $config->queueRateLimits[$queue] ?? 0;
            if ($rateLimit > 0) {
                $queueStats['rate_limit'] = [
                    'max_per_minute' => $rateLimit,
                    'current_usage'  => $rateLimiter->getUsage($queue),
                ];
            }

            $stats['queues'][$queue] = $queueStats;
        }

        // Job execution stats (last 24 hours)
        if ($config->logPerformance && $logModel) {
            $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));

            $stats['last_24h'] = [
                'total_executions' => $logModel->where('created_at >=', $yesterday)->countAllResults(false),
                'successes'        => $logModel->where('created_at >=', $yesterday)
                    ->where('error', null)
                    ->countAllResults(false),
                'failures' => $logModel->where('created_at >=', $yesterday)
                    ->where('error !=', null)
                    ->countAllResults(false),
            ];

            // Average processing time
            $avgTime = $logModel->selectAvg('duration')
                ->where('created_at >=', $yesterday)
                ->get()
                ->getRow();

            $stats['last_24h']['avg_duration'] = $avgTime->duration ?? 0;
        }

        return $stats;
    }

    private function displayStats(array $stats): void
    {
        CLI::newLine();
        CLI::write('╔════════════════════════════════════════════════╗', 'cyan');
        CLI::write('║         Jobs System Health Check               ║', 'cyan');
        CLI::write('╚════════════════════════════════════════════════╝', 'cyan');
        CLI::newLine();

        CLI::write('Timestamp: ' . $stats['timestamp'], 'yellow');
        CLI::newLine();

        // Config
        CLI::write('Configuration:', 'green');
        CLI::write('  Retry Strategy: ' . $stats['config']['retry_strategy']);
        CLI::write('  Job Timeout: ' . $stats['config']['job_timeout'] . 's');
        CLI::write('  Dead Letter Queue: ' . $stats['config']['dead_letter_queue']);
        CLI::write('  Batch Size: ' . $stats['config']['batch_size']);
        CLI::newLine();

        // Queues
        CLI::write('Queue Status:', 'green');

        foreach ($stats['queues'] as $name => $queueStats) {
            CLI::write("  [{$name}]", 'yellow');
            CLI::write("    Pending: {$queueStats['pending']}");
            CLI::write("    Processing: {$queueStats['processing']}");
            CLI::write("    Completed: {$queueStats['completed']}");
            CLI::write("    Failed: {$queueStats['failed']}");

            if (isset($queueStats['rate_limit'])) {
                $rl = $queueStats['rate_limit'];
                CLI::write("    Rate Limit: {$rl['current_usage']}/{$rl['max_per_minute']} per minute");
            }
            CLI::newLine();
        }

        // Last 24h stats
        if (isset($stats['last_24h'])) {
            CLI::write('Last 24 Hours:', 'green');
            CLI::write('  Total Executions: ' . $stats['last_24h']['total_executions']);
            CLI::write('  Successes: ' . $stats['last_24h']['successes'], 'green');
            CLI::write('  Failures: ' . $stats['last_24h']['failures'], 'red');
            CLI::write('  Avg Duration: ' . round($stats['last_24h']['avg_duration'], 2) . 's');
        }

        CLI::newLine();
    }
}
