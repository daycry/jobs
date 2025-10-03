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

final class RequeueHelper
{
    public function __construct(private ?MetricsCollectorInterface $metrics = null)
    {
    }

    public function finalize(Job $job, JobEnvelope $envelope, callable $removeFn, bool $success): void
    {
        if ($success) {
            $removeFn($job, false);
            $this->metrics?->increment('jobs_succeeded', 1, ['queue' => $envelope->queue]);

            return;
        }
        // failure path
        $job->addAttempt();
        $removeFn($job, true);
        $this->metrics?->increment('jobs_failed', 1, ['queue' => $envelope->queue]);
        $this->metrics?->increment('jobs_requeued', 1, ['queue' => $envelope->queue]);
    }
}
