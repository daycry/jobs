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
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\Queues\RedisQueue;

/**
 * Reclaim Redis-queued jobs that crashed before being acked.
 *
 * Walks the {prefix}{queue}-processing-meta hash and re-enqueues any item whose
 * lease is older than the configured (or supplied) visibility timeout. Intended
 * to be run periodically (e.g. once a minute via system cron) when Redis is the
 * active worker.
 */
class RedisReapStuckCommand extends BaseCommand
{
    protected $group       = 'Jobs';
    protected $name        = 'jobs:redis:reap-stuck';
    protected $description = 'Re-enqueue Redis jobs left in the processing list past the visibility timeout.';
    protected $usage       = 'jobs:redis:reap-stuck [--queue=<name>] [--timeout=<seconds>]';
    protected $options     = [
        '--queue'   => 'Queue name to inspect (default: "default").',
        '--timeout' => 'Visibility timeout in seconds. Defaults to config Jobs::redisProcessingVisibilityTimeout.',
    ];

    public function run(array $params): void
    {
        $queue   = $params['queue'] ?? CLI::getOption('queue') ?? 'default';
        $timeout = $params['timeout'] ?? CLI::getOption('timeout') ?? null;

        $worker = QueueManager::instance()->get('redis');
        if (! $worker instanceof RedisQueue) {
            CLI::error('Configured "redis" worker is not an instance of RedisQueue.');

            return;
        }

        $reaped = $worker->reapStuckJobs((string) $queue, $timeout !== null ? (int) $timeout : null);

        if ($reaped === 0) {
            CLI::write("No stuck jobs found in queue '{$queue}'.", 'green');

            return;
        }

        CLI::write("Reclaimed {$reaped} stuck job(s) in queue '{$queue}'.", 'yellow');
    }
}
