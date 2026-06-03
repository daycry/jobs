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

use CodeIgniter\CLI\CLI;
use Daycry\Jobs\Queues\BackendFactory;

/**
 * Reclaims queue messages whose visibility timeout expired (crashed/stalled worker recovery).
 * Works for every v3 backend via QueueBackend::reapExpired(). Intended to run periodically
 * (e.g. once per minute from system cron) so jobs left 'in_progress' do not strand.
 */
final class QueueReapCommand extends BaseJobsCommand
{
    protected $name        = 'jobs:queue:reap';
    protected $description = 'Reclaim queue messages whose visibility timeout expired.';
    protected $usage       = 'jobs:queue:reap [queue] [--backend name]';
    protected $arguments   = ['queue' => 'The queue name to reap.'];
    protected $options     = ['--backend' => 'Override the configured backend name.'];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $this->getConfig();

        $queue = $params['queue'] ?? $params[0] ?? CLI::getOption('queue');
        if (! is_string($queue) || $queue === '') {
            CLI::error('A queue name is required: jobs:queue:reap <queue>');

            return self::FAILURE;
        }

        $backendOption = $params['backend'] ?? CLI::getOption('backend');
        $name          = is_string($backendOption) && $backendOption !== '' ? $backendOption : $this->config->worker;

        $backend = BackendFactory::make($this->config, $name);
        $timeout = $name === 'redis'
            ? $this->config->redisProcessingVisibilityTimeout
            : $this->config->databaseVisibilityTimeout;

        $reaped = $backend->reapExpired($queue, $timeout);
        CLI::write("Reaped {$reaped} expired message(s) from queue '{$queue}'.", 'green');

        return self::SUCCESS;
    }
}
