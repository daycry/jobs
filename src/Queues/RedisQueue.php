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
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as QueuesJob; // ext-redis required
use Daycry\Jobs\Libraries\RedisHandler as JobsRedisHandler;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Redis-backed queue implementation with reliable-queue semantics.
 *
 * Storage model (one set of keys per queue):
 *  - {prefix}{queue}-waiting          LIST  jobs ready to fetch (LPUSH at tail, RPOPLPUSH at head).
 *  - {prefix}{queue}-delayed          ZSET  jobs scheduled for the future (score = due timestamp).
 *  - {prefix}{queue}-processing       LIST  in-flight jobs picked up by a worker (atomic via RPOPLPUSH).
 *  - {prefix}{queue}-processing-meta  HASH  raw payload → unix timestamp when fetched (used by the reaper).
 *
 * Contract notes:
 *  - enqueue(): returns a string job identifier (timestamp-randhex).
 *  - watch(): promotes due delayed jobs then atomically moves one item from waiting to processing.
 *             When config('Jobs')->blockingFetch is true, uses BRPOPLPUSH with blockingFetchTimeout.
 *  - removeJob(): ack (recreate=false) drops the item from processing; nack (recreate=true) moves it
 *                 back to the tail of waiting so it gets retried.
 *  - reapStuckJobs(): inspects processing-meta and re-enqueues items whose lease exceeded the
 *                      visibility timeout. Should be invoked periodically (e.g. via jobs:redis:reap-stuck).
 */
class RedisQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    /**
     * @var object|null Redis client instance when ext-redis is available
     */
    private $redis;

    private ?object $job    = null; // decoded structure { id,time,delay,data }
    private ?string $rawJob = null; // raw serialised payload kept for ack/nack
    private string $prefix  = 'jobs:';

    public function __construct()
    {
        if (! class_exists('Redis')) {
            return;
        }

        try {
            $cacheConfig          = clone config(Cache::class);
            $cacheConfig->handler = 'redis';
            $handler              = new JobsRedisHandler($cacheConfig);
            $handler->initialize();
            $this->redis = $handler->getRedis();
        } catch (Throwable $e) {
            log_message('warning', 'RedisQueue: connection failed — ' . $e->getMessage());
            $this->redis = null;
        }
    }

    public function enqueue(object $data): string
    {
        $queue   = $data->queue ?? 'default';
        $delay   = $this->calculateDelay($data);
        $now     = time();
        $id      = $now . '-' . $this->generateId(bytes: 4);
        $payload = $this->getSerializer()->serialize((object) [
            'id'    => $id,
            'time'  => $now,
            'delay' => $delay->seconds,
            'data'  => $data,
        ]);
        if (! $this->redis) {
            throw new RuntimeException('Redis extension not available');
        }
        if (! $delay->isImmediate()) {
            $this->redis->zAdd($this->delayedKey($queue), $now + $delay->seconds, $payload);
        } else {
            $this->redis->lPush($this->waitingKey($queue), $payload);
        }

        log_message('debug', 'Job enqueued in Redis queue ' . $queue . ' with ID ' . $id);

        return $id;
    }

    public function watch(string $queue): mixed
    {
        if (! $this->redis) {
            return null;
        }
        $this->promoteDelayed($queue);

        $cfg = config('Jobs');
        $raw = null;

        if (($cfg->blockingFetch ?? false) === true && method_exists($this->redis, 'brpoplpush')) {
            $timeout = max(0, (int) ($cfg->blockingFetchTimeout ?? 5));

            try {
                $raw = $this->redis->brpoplpush(
                    $this->waitingKey($queue),
                    $this->processingKey($queue),
                    $timeout,
                );
            } catch (Throwable $e) {
                log_message('warning', 'RedisQueue: brpoplpush failed, falling back to rpoplpush: ' . $e->getMessage());
                $raw = null;
            }
        }

        if ($raw === null || $raw === false) {
            $raw = $this->redis->rpoplpush(
                $this->waitingKey($queue),
                $this->processingKey($queue),
            );
        }

        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        // Track when the item entered processing so the reaper can rescue stuck jobs.
        $this->redis->hSet($this->processingMetaKey($queue), $raw, (string) time());

        $decoded = $this->getSerializer()->deserialize($raw);
        if (! $decoded) {
            // Corrupt entry: remove from processing so the reaper does not get stuck on it.
            $this->dropFromProcessing($queue, $raw);

            return null;
        }

        $this->job    = $decoded;
        $this->rawJob = $raw;

        $decodedPayload = $this->job->data ?? null;
        if ($decodedPayload) {
            return JobEnvelope::fromBackend(
                backend: 'redis',
                id: (string) ($this->job->id ?? ''),
                queue: $queue,
                payload: $decodedPayload,
                extraMeta: [
                    'delay' => $this->job->delay ?? 0,
                    'time'  => $this->job->time ?? null,
                ],
                raw: $this->job,
            );
        }

        return null;
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        if (! $this->redis) {
            return false;
        }

        $queue = $job->getQueue() ?? 'default';
        $raw   = $this->rawJob;

        if ($raw === null) {
            // No tracked in-flight item: keep legacy behaviour for callers that
            // construct a fresh worker just to push a recreate.
            if ($recreate) {
                $this->enqueue($job->toObject());
            }
            $this->job = null;

            return true;
        }

        if ($recreate) {
            $this->redis->multi()
                ->lRem($this->processingKey($queue), $raw, 1)
                ->lPush($this->waitingKey($queue), $raw)
                ->hDel($this->processingMetaKey($queue), $raw)
                ->exec();
        } else {
            $this->dropFromProcessing($queue, $raw);
        }

        $this->job    = null;
        $this->rawJob = null;

        return true;
    }

    /**
     * Reclaim items left in the processing list whose visibility timeout has expired.
     *
     * Returns the number of items moved back to the waiting list. Intended to be invoked
     * periodically (e.g. via the jobs:redis:reap-stuck command) so jobs left behind by a
     * crashed worker are eventually retried.
     */
    public function reapStuckJobs(string $queue, ?int $visibilityTimeoutSeconds = null): int
    {
        if (! $this->redis) {
            return 0;
        }

        $cfg     = config('Jobs');
        $timeout = $visibilityTimeoutSeconds ?? (int) ($cfg->redisProcessingVisibilityTimeout ?? 300);
        $now     = time();
        $reaped  = 0;

        $entries = $this->redis->hGetAll($this->processingMetaKey($queue));
        if (! is_array($entries)) {
            return 0;
        }

        foreach ($entries as $raw => $startedAt) {
            $age = $now - (int) $startedAt;
            if ($age <= $timeout) {
                continue;
            }

            $this->redis->multi()
                ->lRem($this->processingKey($queue), (string) $raw, 1)
                ->lPush($this->waitingKey($queue), (string) $raw)
                ->hDel($this->processingMetaKey($queue), (string) $raw)
                ->exec();
            $reaped++;
            log_message('warning', "RedisQueue::reapStuckJobs: requeued stuck job in queue '{$queue}' (age={$age}s)");
        }

        return $reaped;
    }

    private function dropFromProcessing(string $queue, string $raw): void
    {
        $this->redis->multi()
            ->lRem($this->processingKey($queue), $raw, 1)
            ->hDel($this->processingMetaKey($queue), $raw)
            ->exec();
    }

    private function promoteDelayed(string $queue): void
    {
        if (! $this->redis) {
            return;
        }
        $now = time();

        // Atomic promotion via Lua script to prevent duplicate job execution
        // under concurrent workers. Falls back to non-atomic approach if eval fails.
        $lua = <<<'LUA'
            local items = redis.call('ZRANGEBYSCORE', KEYS[1], 0, ARGV[1], 'LIMIT', 0, 50)
            for _, item in ipairs(items) do
                if redis.call('ZREM', KEYS[1], item) == 1 then
                    redis.call('LPUSH', KEYS[2], item)
                end
            end
            return #items
            LUA;

        try {
            $this->redis->eval($lua, [$this->delayedKey($queue), $this->waitingKey($queue), (string) $now], 2);
        } catch (Throwable) {
            // Fallback: non-atomic promotion with zRem check.
            // Each item is individually removed before pushing, preventing duplicates
            // when multiple workers execute this path concurrently.
            log_message('warning', 'Redis Lua eval failed for queue promotion; using non-atomic fallback for queue: ' . $queue);

            $items = $this->redis->zRangeByScore($this->delayedKey($queue), 0, $now, ['limit' => [0, 50]]);
            if (! $items) {
                return;
            }

            foreach ($items as $raw) {
                // zRem returns > 0 only for the first worker that removes this item
                if ($this->redis->zRem($this->delayedKey($queue), $raw) > 0) {
                    $this->redis->lPush($this->waitingKey($queue), $raw);
                }
            }
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

    private function processingKey(string $queue): string
    {
        return $this->prefix . $queue . '-processing';
    }

    private function processingMetaKey(string $queue): string
    {
        return $this->prefix . $queue . '-processing-meta';
    }
}
