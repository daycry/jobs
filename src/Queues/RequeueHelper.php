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
        if (! $this->metrics instanceof MetricsCollectorInterface) {
            $this->metrics = Metrics::get();
        }

        // Lazy resolve DLQ
        if (! $this->dlq instanceof DeadLetterQueue) {
            $this->dlq = new DeadLetterQueue();
        }

        // The current cycle's attempt number = persisted counter + 1.
        // We delay the actual addAttempt() call until we know the destination so the
        // counter stays consistent with the backend state if removeFn or DLQ fails.
        $maxRetries  = $job->getMaxRetries();
        $nextAttempt = $job->getAttempt() + 1;

        if ($success) {
            $job->addAttempt();
            $removeFn($job, false);
            $this->metrics?->increment('jobs_succeeded', 1, ['queue' => $envelope->queue]);

            return;
        }

        // Failure path. Requeue only when retries remain.
        $shouldRequeue = ($maxRetries !== null) && ($nextAttempt < ($maxRetries + 1));

        if ($shouldRequeue) {
            $job->addAttempt(); // counter is persisted into the requeued payload via toObject()
            $removeFn($job, true);
            $this->metrics?->increment('jobs_failed', 1, ['queue' => $envelope->queue]);
            $this->metrics?->increment('jobs_requeued', 1, ['queue' => $envelope->queue]);

            return;
        }

        // Permanent failure: try DLQ FIRST so we know whether the origin removal is safe.
        // store() returns true only when the job was persisted to the DLQ backend.
        $stored = $this->dlq->store($job, 'Max retries exceeded', $nextAttempt);

        $job->addAttempt();
        $removeFn($job, false);
        $this->metrics?->increment('jobs_failed', 1, ['queue' => $envelope->queue]);
        $this->metrics?->increment('jobs_failed_permanently', 1, ['queue' => $envelope->queue]);

        if (! $stored) {
            // DLQ disabled or push failed. The job has been removed from the origin queue
            // (in some backends — Redis/ServiceBus — the message is gone after fetch and
            // we cannot leave it there). Track this as a separate signal so operators can
            // monitor it as a job-loss indicator and configure DLQ.
            $this->metrics?->increment('jobs_dlq_failed', 1, ['queue' => $envelope->queue]);
        }
    }
}
