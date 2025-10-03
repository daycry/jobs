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

use Config\Cache;
use DateTimeImmutable;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as QueuesJob; // ext-redis required
use Daycry\Jobs\Libraries\DateTimeHelper;
use Daycry\Jobs\Libraries\RedisHandler as JobsRedisHandler;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Redis-backed queue implementation.
 *
 * Storage model:
 *  - Immediate jobs: LPUSH into {prefix}{queue}-waiting, consumed via RPOP (FIFO semantics).
 *  - Delayed jobs: stored in a sorted set {prefix}{queue}-delayed with score = due timestamp, then promoted.
 *  - (Future) Failed jobs key reserved {prefix}{queue}-failed (not yet persisted here).
 *
 * Contract notes:
 *  - enqueue(): returns a string job identifier (timestamp-randhex).
 *  - watch(): promotes due delayed jobs then pops one waiting job; returns decoded stdClass or null.
 *  - removeJob(): if $recreate true it re-dispatches through Job::enqueue preserving retry semantics.
 */
class RedisQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    /**
     * @var object|null Redis client instance when ext-redis is available
     */
    private $redis;

    private ?object $job = null; // decoded structure { id,time,delay,data }
    private string $prefix;

    public function __construct()
    {
        $this->prefix = 'jobs:';
        if (! class_exists('Redis')) {
            return;
        }

        try {
            $cacheConfig          = config(Cache::class);
            $cacheConfig->handler = 'redis';
            $handler              = new JobsRedisHandler($cacheConfig);
            $handler->initialize();
            $this->redis = $handler->getRedis();
        } catch (Throwable) {
            $this->redis = null; // swallow; enqueue/watch will handle absence
        }
    }

    public function enqueue(object $data): string
    {
        $queue = $data->queue ?? 'default';
        $this->calculateDelay($data);
        $now     = time();
        $id      = $now . '-' . bin2hex(random_bytes(4));
        $payload = json_encode([
            'id'    => $id,
            'time'  => $now,
            'delay' => $this->getDelay(),
            'data'  => $data,
        ]);
        if (! $this->redis) {
            throw new RuntimeException('Redis extension not available');
        }
        if ($this->getDelay() > 0) {
            $this->redis->zAdd($this->delayedKey($queue), $now + $this->getDelay(), $payload);
        } else {
            $this->redis->lPush($this->waitingKey($queue), $payload);
        }

        log_message('debug', 'Job enqueued in Redis queue ' . $queue . ' with ID ' . $id);
        return $id;
    }

    public function watch(string $queue)
    {
        if (! $this->redis) {
            return null;
        }
        $this->promoteDelayed($queue);
        $raw = $this->redis->rPop($this->waitingKey($queue));
        if (! $raw) {
            return null;
        }
        $this->job = json_decode($raw);
        // Build JobEnvelope (keeping legacy structure in raw)
        $decodedPayload = $this->job->data ?? null;
        if ($decodedPayload) {
            $createdAt = DateTimeHelper::parseImmutable($decodedPayload->createdAt ?? null)
                ?? (isset($decodedPayload->time) ? (new DateTimeImmutable())->setTimestamp((int) $decodedPayload->time) : DateTimeHelper::now());

            return JobEnvelope::fromDecoded(
                id: (string) ($this->job->id ?? ''),
                queue: $queue,
                decoded: $decodedPayload,
                attempts: (int) ($decodedPayload->attempts ?? 0),
                priority: null,
                scheduledAt: null,
                availableAt: isset($decodedPayload->time) ? (new DateTimeImmutable())->setTimestamp((int) $decodedPayload->time) : null,
                createdAt: $createdAt,
                meta: ['delay' => $this->job->delay ?? 0],
                raw: $this->job,
            );
        }

        return null; // Should not happen, but keeps contract explicit
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        if ($recreate) {
            $job->addAttempt();
            $job->push();
        }
        $this->job = null;

        return true;
    }

    private function promoteDelayed(string $queue): void
    {
        if (! $this->redis) {
            return;
        }
        $now   = time();
        $items = $this->redis->zRangeByScore($this->delayedKey($queue), 0, $now, ['limit' => [0, 50]]);
        if (! $items) {
            return;
        }

        foreach ($items as $raw) {
            $this->redis->zRem($this->delayedKey($queue), $raw);
            $this->redis->lPush($this->waitingKey($queue), $raw);
        }
    }

    private function waitingKey(string $queue): string
    {
        return $this->prefix . $queue . '-waiting';
    }

    private function delayedKey(string $queue): string
    {
        return $this->prefix . $queue . '-delayed';
    }

    private function failedKey(string $queue): string
    {
        return $this->prefix . $queue . '-failed';
    }
}
