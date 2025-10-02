<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Jobs.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Daycry\Jobs\Queues;

use DateTime;
use DateTimeZone;
use Daycry\Jobs\Entities\Queue as QueueEntity;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as QueuesJob;
use Daycry\Jobs\Models\QueueModel;

class DatabaseQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    private int $priority = 0;
    private mixed $job    = null;

    public function enqueue(object $data): mixed
    {
        helper('text');

        $identifier = random_string('alnum', 32);

        $queueModel = new QueueModel();
        $job        = new QueueEntity();

        $this->calculateDelay($data);

        if ($this->getDelay() > 0) {
            $data->schedule = $data->schedule;
        } else {
            $data->schedule = new DateTime('now', new DateTimeZone(service('settings')->get('App.appTimezone')));
        }

        $data->identifier = $identifier;

        $job->queue       = $data->queue;
        $job->payload     = \json_encode($data);
        $job->priority    = $data->priority;
        $job->schedule    = $data->schedule->format('Y-m-d H:i:s');
        $job->identifier  = $identifier;
        $job->status      = 'pending';

        $queueModel->insert($job);

        return $identifier;
    }

    public function watch(string $queue): ?QueueEntity
    {
        $queueModel = new QueueModel();

        $this->job = $queueModel->getJob();

        if ($this->job !== null) {
            $this->job->status     = 'in_progress';
            $this->job->updated_at = date('Y-m-d H:i:s');
            $queueModel->update($this->job->id, $this->job);
        }

        return $this->job;
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        $queueModel = new QueueModel();

        if ($recreate === true) {
            $this->job->status = 'failed';
            $queueModel->update($this->job->id, $this->job);

            $job->addAttempt();
            $job->enqueue($job->getQueue());
        } else {
            $this->job->status = 'completed';
            $queueModel->update($this->job->id, $this->job);
        }

        $this->job = null;

        return true;
    }

    public function getDataJob()
    {
        return \json_decode($this->job->payload);
    }

    public function setPriority(int $priority)
    {
        $this->priority = $priority;

        return $this;
    }
}
