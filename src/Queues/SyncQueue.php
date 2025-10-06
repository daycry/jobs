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

namespace Daycry\Jobs\Queues;

use Daycry\Jobs\Execution\ExecutionContext;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
/**
 * Synchronous (in-process) queue implementation.
 *
 * Contract notes:
 *  - enqueue(): executes the job immediately through JobExecutor and returns a synthetic identifier.
 *  - No watch()/removeJob(): it is not a WorkerInterface implementation by design.
 *
 * Use cases: testing, local development, or fallback when no async backend is desired.
 */
use Daycry\Jobs\Job;
use Daycry\Jobs\Job as DomainJob;

class SyncQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    public function enqueue(object $data): string
    {
        $identifier = 'sync-' . bin2hex(random_bytes(6));
        if ($data instanceof Job) {
            $job = $data; // preserve callbackDescriptor & closures
        } else {
            $data->identifier = $identifier;
            $job              = Job::fromQueueRecord($data);
        }

        $cfg = config('Jobs');
        if ($data instanceof Job) {
            $queueName = $job->getQueue() ?? 'default';
        } else {
            $queueName = $job->getQueue() ?? ($data->queue ?? 'default');
        }
        $context = new ExecutionContext(
            source: 'queue',
            maxRetries: $job->getMaxRetries() ?? 0,
            notifyOnSuccess: method_exists($job, 'shouldNotifyOnSuccess') ? $job->shouldNotifyOnSuccess() : false,
            notifyOnFailure: method_exists($job, 'shouldNotifyOnFailure') ? $job->shouldNotifyOnFailure() : false,
            singleInstance: $job->isSingleInstance(),
            queueName: $queueName,
            queueWorker: $this,
            retryConfig: [
                'strategy'   => $cfg->retryBackoffStrategy,
                'base'       => $cfg->retryBackoffBase,
                'multiplier' => $cfg->retryBackoffMultiplier,
                'jitter'     => $cfg->retryBackoffJitter,
                'max'        => $cfg->retryBackoffMax,
            ],
            eventsEnabled: true,
            meta: [],
        );

        (new JobLifecycleCoordinator())->run($job, $context);

        return $identifier;
    }

    // WorkerInterface implementation (minimal / no-op)
    public function watch(string $queue)
    {
        return null; // Sync queue doesn't support pulling jobs (they run immediately)
    }

    public function removeJob(DomainJob $job, bool $recreate = false): bool
    {
        // In sync mode retries would already have been performed inline; nothing to remove.
        return true;
    }
}
