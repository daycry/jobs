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

use Throwable;
use DateTime;
use DateTimeZone;
use Daycry\Jobs\Entities\Queue as QueueEntity;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as QueuesJob;
use Daycry\Jobs\Models\QueueModel;

/**
 * Database-backed queue (persistent) implementing scheduling & status tracking.
 * watch(): devuelve QueueEntity o null.
 */
class DatabaseQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    private mixed $job    = null;

    public function enqueue(object $data): string
    {
        helper('text');

        $identifier = $this->generateId(bytes: 4);

        $queueModel = new QueueModel();
        $job        = new QueueEntity();

        $delay = $this->calculateDelay($data);

        if ($delay->isImmediate()) {
            $data->schedule = new DateTime('now', new DateTimeZone(config('App')->appTimezone));
        }

        $data->identifier = $identifier;
        $job->queue       = $data->queue;
        $job->payload     = \json_encode($data);
        $job->priority    = $data->priority;
        $job->schedule    = $data->schedule->format('Y-m-d H:i:s');
        $job->identifier  = $identifier;
        $job->status      = 'pending';
        $job->max_retries = $data->maxRetries ?? null;
        $job->attempts    = $data->attempts ?? 0;

        $queueModel->insert($job);

        return $identifier;
    }

    public function watch(string $queue): ?JobEnvelope
    {
        $queueModel = new QueueModel();

        $this->job = $queueModel->reserveJob($queue);

        if ($this->job instanceof QueueEntity) {
            $decoded = \json_decode($this->job->payload ?? '{}');

            return JobEnvelope::fromBackend(
                backend: 'database',
                id: (string) $this->job->identifier,
                queue: (string) $this->job->queue,
                payload: $decoded,
                extraMeta: [
                    'entity_id' => $this->job->id,
                    'status'    => $this->job->status,
                    'schedule'  => $this->job->schedule ?? null,
                ],
                raw: $this->job,
            );
        }

        return null;
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        $queueModel = new QueueModel();

        if ($recreate) {
            // Update status and re-enqueue. If re-enqueue fails, restore previous status.
            $previousStatus = null;
            if ($this->job !== null) {
                $previousStatus    = $this->job->status;
                $this->job->status = 'failed';
                $queueModel->update($this->job->id, $this->job);
            }
            try {
                $this->enqueue($job->toObject());
            } catch (Throwable $e) {
                // Rollback status change if re-enqueue fails
                if ($this->job !== null && $previousStatus !== null) {
                    $this->job->status = $previousStatus;
                    $queueModel->update($this->job->id, $this->job);
                }
                log_message('error', 'DatabaseQueue::removeJob re-enqueue failed: ' . $e->getMessage());

                throw $e;
            }
        } elseif ($this->job !== null) {
            // Completion: mark as completed
            $this->job->status = 'completed';
            $queueModel->update($this->job->id, $this->job);
        }

        $this->job = null;

        return true;
    }

    public function setPriority(int $priority)
    {
        return $this;
    }
}
