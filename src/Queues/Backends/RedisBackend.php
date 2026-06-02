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

namespace Daycry\Jobs\Queues\Backends;

use Config\Cache;
use Daycry\Jobs\Libraries\RedisHandler as JobsRedisHandler;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use Daycry\Jobs\V2\JobDefinition;
use Redis;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Redis-backed queue implementing the v3 {@see QueueBackend} contract.
 *
 * Storage model per queue (prefix 'jobs:'):
 *  - {q}-waiting          LIST   ready messages (LPUSH tail, RPOPLPUSH head).
 *  - {q}-delayed          ZSET   future messages (score = due unix ts).
 *  - {q}-processing       LIST   in-flight messages (atomic move via RPOPLPUSH).
 *  - {q}-processing-meta  HASH   raw payload => json{ts, owner} (visibility / ownership).
 *
 * v3 changes over the legacy RedisQueue:
 *  - nack() RE-SERIALISES the payload with attempts+1 (the legacy code re-pushed the original
 *    raw string, so the incremented attempt counter was lost on Redis).
 *  - nack(delay) honours the delay by sending the message to the delayed ZSET.
 *  - reapExpired() recovers messages whose visibility timeout elapsed; renewLease() lets a
 *    long-running worker extend the lease so the reaper does not reclaim a live job.
 *
 * Delivery is at-least-once; handlers should be idempotent.
 */
final class RedisBackend implements QueueBackend
{
    private const BACKEND = 'redis';
    private ?Redis $redis = null;
    private string $prefix = 'jobs:';

    public function __construct()
    {
        if (! class_exists(Redis::class)) {
            return;
        }

        try {
            $cacheConfig          = clone config(Cache::class);
            $cacheConfig->handler = 'redis';
            $handler              = new JobsRedisHandler($cacheConfig);
            $handler->initialize();
            $this->redis = $handler->getRedis();
        } catch (Throwable $e) {
            log_message('warning', 'RedisBackend: connection failed — ' . $e->getMessage());
            $this->redis = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->redis instanceof Redis;
    }

    public function enqueue(JobDefinition $definition): string
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            throw new RuntimeException('Redis extension/connection not available');
        }

        $queue      = $definition->queue ?? 'default';
        $identifier = bin2hex(random_bytes(8));
        $wire       = EnvelopeFactory::toWire($definition, $identifier);
        $raw        = json_encode($wire, JSON_THROW_ON_ERROR);

        $now   = time();
        $due   = $definition->scheduledAt?->getTimestamp() ?? $now;
        $delay = max(0, $due - $now);

        if ($delay > 0) {
            $redis->zAdd($this->delayedKey($queue), $now + $delay, $raw);
        } else {
            $redis->lPush($this->waitingKey($queue), $raw);
        }

        return $identifier;
    }

    public function fetch(string $queue): ?JobLease
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return null;
        }

        $this->promoteDelayed($queue);

        $raw = $redis->rpoplpush($this->waitingKey($queue), $this->processingKey($queue));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $owner             = bin2hex(random_bytes(16));
        $visibilityTimeout = (int) (config('Jobs')->redisProcessingVisibilityTimeout ?? 300);
        $redis->hSet($this->processingMetaKey($queue), $raw, json_encode(['ts' => time(), 'owner' => $owner], JSON_THROW_ON_ERROR));

        $wire = json_decode($raw);
        if (! $wire instanceof stdClass) {
            // Corrupt entry: drop it so the reaper does not get stuck on it.
            $this->dropFromProcessing($queue, $raw);

            return null;
        }

        $envelope = new JobEnvelope(
            id: isset($wire->identifier) ? (string) $wire->identifier : '',
            queue: $queue,
            payload: $wire,
            name: isset($wire->name) && is_string($wire->name) ? $wire->name : null,
            attempts: isset($wire->attempts) ? (int) $wire->attempts : 0,
            priority: isset($wire->priority) ? (int) $wire->priority : null,
            meta: ['backend' => self::BACKEND, 'owner' => $owner],
            raw: $wire,
        );

        return JobLease::withRelativeExpiry($envelope, $raw, $owner, $visibilityTimeout, self::BACKEND);
    }

    public function ack(JobLease $lease): bool
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return false;
        }

        $this->dropFromProcessing($lease->envelope->queue, $lease->token);

        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return false;
        }

        $queue = $lease->envelope->queue;
        $raw   = $lease->token;

        // Re-serialise with attempts+1 so the incremented counter survives the requeue.
        $wire = json_decode($raw);
        if ($wire instanceof stdClass) {
            $wire->attempts = (isset($wire->attempts) ? (int) $wire->attempts : 0) + 1;
            $newRaw         = json_encode($wire, JSON_THROW_ON_ERROR);
        } else {
            $newRaw = $raw;
        }

        $delay = max(0, $delaySeconds ?? 0);

        $redis->multi();
        $redis->lrem($this->processingKey($queue), $raw, 1);
        $redis->hDel($this->processingMetaKey($queue), $raw);
        if ($delay > 0) {
            $redis->zAdd($this->delayedKey($queue), time() + $delay, $newRaw);
        } else {
            $redis->lPush($this->waitingKey($queue), $newRaw);
        }
        $redis->exec();

        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return false;
        }

        // No retry: just drop it from processing. The runtime forwards to the DLQ first.
        $this->dropFromProcessing($lease->envelope->queue, $lease->token);

        return true;
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return 0;
        }

        $entries = $redis->hGetAll($this->processingMetaKey($queue));
        if (! is_array($entries)) {
            return 0;
        }

        $now    = time();
        $reaped = 0;

        foreach ($entries as $raw => $metaJson) {
            $meta = json_decode($metaJson, true);
            $ts   = is_array($meta) && isset($meta['ts']) ? (int) $meta['ts'] : 0;
            if ($now - $ts <= $visibilityTimeout) {
                continue;
            }

            $redis->multi();
            $redis->lrem($this->processingKey($queue), (string) $raw, 1);
            $redis->lPush($this->waitingKey($queue), (string) $raw);
            $redis->hDel($this->processingMetaKey($queue), (string) $raw);
            $redis->exec();
            $reaped++;
        }

        return $reaped;
    }

    /**
     * Extend the visibility deadline of a held lease (worker heartbeat). Re-stamps the
     * processing-meta timestamp so the reaper does not reclaim a still-running job.
     */
    public function renewLease(JobLease $lease): bool
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return false;
        }

        $redis->hSet(
            $this->processingMetaKey($lease->envelope->queue),
            $lease->token,
            json_encode(['ts' => time(), 'owner' => $lease->ownerToken], JSON_THROW_ON_ERROR),
        );

        return true;
    }

    private function dropFromProcessing(string $queue, string $raw): void
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return;
        }

        $redis->multi();
        $redis->lrem($this->processingKey($queue), $raw, 1);
        $redis->hDel($this->processingMetaKey($queue), $raw);
        $redis->exec();
    }

    private function promoteDelayed(string $queue): void
    {
        $redis = $this->redis;
        if (! $redis instanceof Redis) {
            return;
        }

        $now = time();

        // Atomic promotion via Lua to prevent duplicate promotion under concurrent workers.
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
            $redis->eval($lua, [$this->delayedKey($queue), $this->waitingKey($queue), (string) $now], 2);
        } catch (Throwable) {
            // Non-atomic fallback: zRem gate prevents duplicates across workers.
            $items = $redis->zRangeByScore($this->delayedKey($queue), '0', (string) $now, ['limit' => [0, 50]]);
            if (! is_array($items)) {
                return;
            }

            foreach ($items as $raw) {
                if ($redis->zRem($this->delayedKey($queue), (string) $raw) > 0) {
                    $redis->lPush($this->waitingKey($queue), (string) $raw);
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
