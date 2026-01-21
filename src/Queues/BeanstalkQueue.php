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

use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as QueuesJob;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\TubeName;
use Throwable;

/**
 * Beanstalkd queue implementation (via pheanstalk/pheanstalk).
 *
 * Contract notes:
 *  - enqueue(): returns Beanstalk internal integer job id as string.
 *  - watch(): reserves one job with timeout (1s poll) and returns JobEnvelope or null.
 *  - removeJob(): deletes the current reserved job; if $recreate true the original Job instance is re-enqueued.
 *
 * Caveats:
 *  - The dependency must exist or construction will fail.
 *  - No explicit release/bury support yet; extend removeJob() for richer semantics later.
 */
class BeanstalkQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    private int $priority           = 0;
    private int $ttr                = 3600; // time-to-run
    private ?Pheanstalk $connection = null;
    private ?Job $job               = null;

    public function __construct()
    {
        // Expect config('Jobs')->beanstalk or similar (adapt if needed)
        $cfg              = config('Jobs')->beanstalk ?? ['host' => '127.0.0.1', 'port' => 11300];
        $this->connection = Pheanstalk::create($cfg['host'] ?? '127.0.0.1', (int) ($cfg['port'] ?? 11300));
    }

    public function enqueue(object $data): string
    {
        $queue = $data->queue ?? 'default';
        $tube  = new TubeName($queue);
        $this->connection->useTube($tube);
        $delay   = $this->calculateDelay($data);
        $payload = $this->getSerializer()->serialize($data);

        return $this->connection->put($payload, $this->priority, $delay->seconds, $this->ttr)->getId();
    }

    public function watch(string $queue)
    {
        $tube = new TubeName($queue);

        // Watch the new tube first, then ignore others to avoid NotIgnoredException
        // (Beanstalk requires at least one tube to be watched)
        $this->connection->watch($tube);

        foreach ($this->connection->listTubesWatched() as $watched) {
            if ((string) $watched !== $queue) {
                $this->connection->ignore($watched);
            }
        }

        // Ensure we're watching the correct tube after ignoring others
        $this->connection->watch($tube);

        // Reserve with timeout - use 3 seconds to allow for requeue delays
        $this->job = $this->connection->reserveWithTimeout(3);
        if (! $this->job) {
            return null;
        }
        $decoded = $this->getSerializer()->deserialize($this->job->getData() ?: '{}');
        if (! $decoded) {
            return null;
        }

        return JobEnvelope::fromBackend(
            backend: 'beanstalk',
            id: (string) $this->job->getId(),
            queue: $queue,
            payload: $decoded,
            extraMeta: [
                'ttr'      => $this->ttr,
                'priority' => $this->priority,
            ],
            raw: $this->job,
        );
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        if ($this->job !== null) {
            try {
                $this->connection->delete($this->job);
            } catch (Throwable) { // ignore
            }
        }

        if ($recreate) {
            // Re-enqueue directly to this beanstalk backend instead of using push()
            // which might use a different worker from QueueManager
            // Force immediate availability by setting delay to 0
            $queue   = $job->getQueue() ?? 'default';
            $tube    = new TubeName($queue);
            $payload = $this->getSerializer()->serialize($job->toObject());
            $this->connection->useTube($tube);
            $this->connection->put($payload, $this->priority, 0, $this->ttr); // delay = 0

            // Small sleep to ensure beanstalkd has processed the job before next reserve
            usleep(50000); // 50ms
        }
        $this->job = null;

        return true;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function setTtr(int $ttr): self
    {
        $this->ttr = $ttr;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getTtr(): int
    {
        return $this->ttr;
    }
}
