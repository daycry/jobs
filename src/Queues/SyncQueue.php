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

use Daycry\Jobs\Execution\JobExecutor;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Job;

/**
 * Synchronous (in-process) queue implementation.
 *
 * Contract notes:
 *  - enqueue(): executes the job immediately through JobExecutor and returns a synthetic identifier.
 *  - No watch()/removeJob(): it is not a WorkerInterface implementation by design.
 *
 * Use cases: testing, local development, or fallback when no async backend is desired.
 */
class SyncQueue extends BaseQueue implements QueueInterface
{
    public function enqueue(object $data): string
    {
        // Generar ID sintético y ejecutar inmediatamente
        $identifier       = 'sync-' . bin2hex(random_bytes(6));
        $data->identifier = $identifier;
        $job              = Job::fromQueueRecord($data);
        $executor         = new JobExecutor();
        $executor->execute($job); // Ignoramos resultado según contrato de enqueue

        return $identifier;
    }
}
