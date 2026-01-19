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
use Daycry\Jobs\Libraries\DeadLetterQueue;
use Daycry\Jobs\Metrics\Metrics;
use Daycry\Jobs\Metrics\MetricsCollectorInterface;

/**
 * Coordinates finalization of a job execution.
 * Unified attempts semantics:
 *  - attempts starts at 0 (never executed)
 *  - each completed execution cycle (success OR failure) increments attempts exactly once
 *  - on failure the increment still reflects that one full run happened before requeue
 *  - requeued jobs therefore arrive at the worker with attempts >= 1
 * Integrates Dead Letter Queue for permanently failed jobs.
 */
final class RequeueHelper
{
    private ?DeadLetterQueue $dlq = null;

    public function __construct(private ?MetricsCollectorInterface $metrics = null)
    {
    }

    public function finalize(Job $job, JobEnvelope $envelope, callable $removeFn, bool $success): void
    {
        // Lazy resolve metrics if not injected (Option B hybrid DI + facade fallback)
        if ($this->metrics === null) {
            $this->metrics = Metrics::get();
        }

        // Lazy resolve DLQ
        if ($this->dlq === null) {
            $this->dlq = new DeadLetterQueue();
        }

        // Authoritative increment for this execution cycle (success or failure)
        $job->addAttempt();

        if ($success) {
            $removeFn($job, false);
            $this->metrics?->increment('jobs_succeeded', 1, ['queue' => $envelope->queue]);

            return;
        }

        // Failure: check if we should requeue based on maxRetries
        $maxRetries     = $job->getMaxRetries();
        $currentAttempt = $job->getAttempt();

        // If maxRetries is null, do NOT requeue (fail immediately)
        // If maxRetries is set, requeue only if attempts < maxRetries + 1 (first attempt + retries)
        $shouldRequeue = ($maxRetries !== null) && ($currentAttempt < ($maxRetries + 1));

        if ($shouldRequeue) {
            $removeFn($job, true); // Requeue
            $this->metrics?->increment('jobs_failed', 1, ['queue' => $envelope->queue]);
            $this->metrics?->increment('jobs_requeued', 1, ['queue' => $envelope->queue]);
        } else {
            $removeFn($job, false); // Mark as failed permanently
            $this->metrics?->increment('jobs_failed', 1, ['queue' => $envelope->queue]);
            $this->metrics?->increment('jobs_failed_permanently', 1, ['queue' => $envelope->queue]);

            // Move to Dead Letter Queue if configured
            $this->dlq->store(
                $job,
                'Max retries exceeded',
                $currentAttempt,
            );
        }
    }
}
