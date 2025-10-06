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

namespace Daycry\Jobs\Traits;

use CodeIgniter\I18n\Time;
use DateInterval;
use DateTime;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Exceptions\QueueException;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Libraries\Utils;
use Daycry\Jobs\Queues\SyncQueue;

/**
 * Queue-centric capabilities: queue selection, scheduling, attempts tracking and priority validation.
 * push(): validates job data and delegates to configured worker enqueue.
 */
trait EnqueuableTrait
{
    /**
     * Number of completed execution cycles for this job.
     * Starts at 0 (never executed). Incremented exactly once per run (success or failure)
     * by RequeueHelper::finalize(). A requeued job therefore keeps its historical attempts
     * count so retry policies can make consistent decisions.
     */
    protected int $attempts = 0;

    protected ?string $queue = null;
    private QueueInterface $worker;
    protected ?DateTime $schedule = null;
    protected int $priority       = 0;

    /**
     * Marks the job to be placed on a queue (assigns/validates queue name).
     * Fluent: returns $this instead of bool so you can chain ->enqueue()->named('...')->priority(...)
     * NOTE: This does NOT actually push the job to the backend. Call push() for that.
     */
    public function enqueue(?string $queue = null): self
    {
        $queues = Utils::parseConfigFile(config('Jobs')->queues);
        // If a queue name is explicitly provided, assign it
        if ($queue !== null) {
            $this->queue = $queue;
        }

        // If still null, take the first available queue (if any)
        if ($this->queue === null && count($queues) > 0) {
            $this->queue = $queues[0];
        }

        if (! in_array($this->queue, $queues, true)) {
            throw QueueException::forInvalidQueue($this->queue);
        }

        return $this;
    }

    public function priority(int $priority): self
    {
        if ($priority < 0 || $priority > 10) {
            throw JobException::forInvalidPriority($priority);
        }

        $this->priority = $priority;

        return $this;
    }

    public function push()
    {
        // Assign default source if not already defined (direct queue usage)
        if (method_exists($this, 'getSource') && method_exists($this, 'source') && $this->getSource() === null) {
            $this->source('queue');
        }
        $this->checkWorker();
        // Fast path for SyncQueue: pass original Job instance so callback descriptor & closures are preserved.
        if ($this->worker instanceof SyncQueue) {
            // Perform validation using an export snapshot; inject placeholder for closure payload.
            $export = $this->toObject();
            if ($this->getJob() === 'closure') {
                $export->payload = '__closure__';
            }
            Utils::checkDataQueue($export, 'queueData');
            if ($this->getJob() !== 'closure') {
                Utils::checkDataQueue($export, $this->getJob());
            }

            return $this->worker->enqueue($this);
        }

        $object = $this->toObject();
        if ($this->getJob() === 'closure') {
            // Validation snapshot with placeholder
            $snapshot          = clone $object;
            $snapshot->payload = '__closure__';
            Utils::checkDataQueue($snapshot, 'queueData');
        } else {
            Utils::checkDataQueue($object, 'queueData');
            Utils::checkDataQueue($object, $this->getJob());
        }

        return $this->worker->enqueue($object);
    }

    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function getQueue(): ?string
    {
        return $this->queue;
    }

    public function scheduled(DateTime|Time $schedule)
    {
        if ($schedule instanceof Time) {
            $schedule = $schedule->toDateTime();
        }

        $this->schedule = $schedule;

        return $this;
    }

    public function addAttempt(): self
    {
        $this->attempts++;

        if ($this->schedule !== null) {
            $this->scheduled((new DateTime())->add(new DateInterval('PT1H')));
        }

        return $this;
    }

    public function getAttempt(): int
    {
        return $this->attempts;
    }

    // No idempotency helpers (reverted)

    protected function checkWorker(): void
    {
        $workers = config('Jobs')->workers ?? [];
        $worker  = config('Jobs')->worker ?? '';

        if (! array_key_exists($worker, $workers)) {
            throw QueueException::forInvalidWorker($worker);
        }

        $this->worker = new $workers[$worker]();
    }
}
