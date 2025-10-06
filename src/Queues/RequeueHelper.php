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

use Daycry\Jobs\Job;
use Daycry\Jobs\Metrics\MetricsCollectorInterface;

/**
 * Coordinates finalization of a job execution.
 * Unified attempts semantics:
 *  - attempts starts at 0 (never executed)
 *  - each completed execution cycle (success OR failure) increments attempts exactly once
 *  - on failure the increment still reflects that one full run happened before requeue
 *  - requeued jobs therefore arrive at the worker with attempts >= 1
 */
final class RequeueHelper
{
    public function __construct(private ?MetricsCollectorInterface $metrics = null)
    {
    }

    public function finalize(Job $job, JobEnvelope $envelope, callable $removeFn, bool $success): void
    {
        // Authoritative increment for this execution cycle (success or failure)
        $job->addAttempt();

        if ($success) {
            $removeFn($job, false);
            $this->metrics?->increment('jobs_succeeded', 1, ['queue' => $envelope->queue]);

            return;
        }

        // Failure: job will be requeued by provided removeFn
        $removeFn($job, true);
        $this->metrics?->increment('jobs_failed', 1, ['queue' => $envelope->queue]);
        $this->metrics?->increment('jobs_requeued', 1, ['queue' => $envelope->queue]);
    }
}
