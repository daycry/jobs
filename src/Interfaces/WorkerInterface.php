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

namespace Daycry\Jobs\Interfaces;

use Daycry\Jobs\Job;

/**
 * Queue consumer (worker) contract.
 * watch(): fetch next available job representation (currently backend specific, will normalize to JobEnvelope).
 * removeJob(): marks the job as completed OR, if $recreate=true, re-dispatches it for retry.
 */
interface WorkerInterface
{
    /**
     * Attempt to reserve / fetch next job from the given queue. Return backend job wrapper or null.
     */
    public function watch(string $queue);

    /**
     * Finalize the current job. If $recreate=true the job will be re-enqueued (incrementing attempts).
     */
    public function removeJob(Job $job, bool $recreate = false): bool;
}
