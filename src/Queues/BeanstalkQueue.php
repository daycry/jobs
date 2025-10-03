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
use Daycry\Jobs\Libraries\DateTimeHelper;
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
        $this->calculateDelay($data);
        $payload = json_encode($data);

        return $this->connection->put($payload, $this->priority, $this->getDelay(), $this->ttr)->getId();
    }

    public function watch(string $queue)
    {
        $tube = new TubeName($queue);
        $this->connection->watch($tube);
        // Reserve with timeout to avoid blocking forever
        $this->job = $this->connection->reserveWithTimeout(1); // 1 second poll
        if (! $this->job) {
            return null;
        }
        $decoded   = json_decode($this->job->getData() ?: '{}');
        $createdAt = DateTimeHelper::parseImmutable($decoded->createdAt ?? null) ?? DateTimeHelper::now();

        return new JobEnvelope(
            id: (string) $this->job->getId(),
            queue: $queue,
            payload: $decoded,
            attempts: isset($decoded->attempts) ? (int) $decoded->attempts : 0,
            priority: $this->priority,
            scheduledAt: DateTimeHelper::parseImmutable($decoded->schedule ?? null),
            availableAt: null,
            createdAt: $createdAt,
            meta: ['ttr' => $this->ttr],
            raw: $this->job,
        );
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        if ($this->job) {
            try {
                $this->connection->delete($this->job);
            } catch (Throwable) { // ignore
            }
        }
        if ($recreate) {
            $job->addAttempt();
            $job->push();
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
