<?php

declare(strict_types=1);

/**
 * This file is part of Daycry jobs.
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

trait EnqueuableTrait
{
    protected int $attempts  = 0;
    protected ?string $queue = null;
    private QueueInterface $worker;
    protected ?DateTime $schedule = null;

    protected int $priority = 0;

    public function enqueue(?string $queue = null): bool
    {
        $queues = Utils::parseConfigFile(config('Jobs')->queues);
        $queue ??= $this->queue;

        if ($queue === null && count($queues) > 0) {
            $this->queue = $queues[0];
        }

        if (! in_array($this->queue, $queues, true)) {
            throw QueueException::forInvalidQueue($this->queue);
        }

        return true;
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
        $object        = $this->toObject();

        Utils::checkDataQueue($object, 'queueData');

        Utils::checkDataQueue($object, $this->getJob());

        $this->checkWorker();

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