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

use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\TubeName;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Beanstalkd-backed queue implementing the v3 {@see QueueBackend} contract
 * (via pda/pheanstalk v5).
 *
 * Ported from the legacy {@see \Daycry\Jobs\Queues\BeanstalkQueue}, reusing its tube
 * watch/ignore juggling and reserve-with-timeout loop, but adapted to the stateless
 * lease model: every in-flight message is represented by a {@see JobLease} whose
 * {@see JobEnvelope::$raw} carries the native {@see Job} object needed for
 * delete/release/bury.
 *
 * v3 changes over the legacy BeanstalkQueue:
 *  - enqueue() returns the wire `identifier` (random, stable across requeues) instead of
 *    the backend's volatile integer job id, for consistency with the other v3 backends.
 *  - nack() RE-SERIALISES the payload with attempts+1 (delete old job + put a fresh one)
 *    so the incremented attempt counter survives the requeue, exactly like RedisBackend.
 *    The native `release` verb is not used because it cannot mutate the job body.
 *  - abandon() buries the job (beanstalkd's native dead-letter facility).
 *
 * Delivery is at-least-once; handlers should be idempotent. beanstalkd auto-recovers
 * leases via the per-job TTR, so {@see reapExpired()} is a no-op (see its docblock).
 */
final class BeanstalkBackend implements QueueBackend
{
    private const BACKEND = 'beanstalk';

    private ?Pheanstalk $connection = null;
    private int $priority           = 0;
    private int $ttr                = 3600;

    public function __construct()
    {
        $config   = config('Jobs');
        $settings = is_array($config->beanstalk) ? $config->beanstalk : [];

        $host = isset($settings['host']) && is_string($settings['host']) ? $settings['host'] : '127.0.0.1';
        $port = isset($settings['port']) ? (int) $settings['port'] : 11300;

        try {
            $this->connection = Pheanstalk::create($host, $port);
        } catch (Throwable $e) {
            log_message('warning', 'BeanstalkBackend: connection failed — ' . $e->getMessage());
            $this->connection = null;
        }
    }

    /**
     * Best-effort liveness probe: issues a cheap command and reports whether the server
     * answered. Used by tests to skip when no beanstalkd is reachable.
     */
    public function isConnected(): bool
    {
        $connection = $this->connection;
        if (! $connection instanceof Pheanstalk) {
            return false;
        }

        try {
            $connection->listTubes();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function enqueue(JobDefinition $definition): string
    {
        $connection = $this->requireConnection();

        $queue      = $definition->queue ?? 'default';
        $identifier = bin2hex(random_bytes(8));
        $wire       = EnvelopeFactory::toWire($definition, $identifier);
        $payload    = json_encode($wire, JSON_THROW_ON_ERROR);

        $now   = time();
        $due   = $definition->scheduledAt?->getTimestamp() ?? $now;
        $delay = max(0, $due - $now);

        $ttr = $this->resolveTtr($definition->timeout);

        $connection->useTube(new TubeName($queue));
        $connection->put($payload, $this->priority, $delay, $ttr);

        return $identifier;
    }

    public function fetch(string $queue): ?JobLease
    {
        $connection = $this->connection;
        if (! $connection instanceof Pheanstalk) {
            return null;
        }

        $tube = new TubeName($queue);

        // Watch the target tube first, then stop watching everything else so a reserve
        // cannot pull from an unrelated tube (beanstalkd requires >=1 watched tube).
        $connection->watch($tube);

        foreach ($connection->listTubesWatched() as $watched) {
            if ((string) $watched !== $queue) {
                $connection->ignore($watched);
            }
        }

        $job = $connection->reserveWithTimeout(5);
        if (! $job instanceof Job) {
            return null;
        }

        $wire = json_decode($job->getData());
        if (! $wire instanceof stdClass) {
            // Corrupt body: bury it so it neither blocks the tube nor is silently lost.
            $connection->bury($job, $this->priority);

            return null;
        }

        $envelope = new JobEnvelope(
            id: isset($wire->identifier) && is_string($wire->identifier) ? $wire->identifier : $job->getId(),
            queue: $queue,
            payload: $wire,
            name: isset($wire->name) && is_string($wire->name) ? $wire->name : null,
            attempts: isset($wire->attempts) ? (int) $wire->attempts : 0,
            priority: isset($wire->priority) ? (int) $wire->priority : null,
            meta: ['backend' => self::BACKEND, 'rawId' => $job->getId()],
            raw: $job,
        );

        return JobLease::withRelativeExpiry($envelope, $job->getId(), bin2hex(random_bytes(16)), $this->ttr, self::BACKEND);
    }

    public function ack(JobLease $lease): bool
    {
        $connection = $this->connection;
        if (! $connection instanceof Pheanstalk) {
            return false;
        }

        $raw = $lease->envelope->raw;
        if (! $raw instanceof Job) {
            return false;
        }

        $connection->delete($raw);

        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        $connection = $this->connection;
        if (! $connection instanceof Pheanstalk) {
            return false;
        }

        $raw = $lease->envelope->raw;
        if (! $raw instanceof Job) {
            return false;
        }

        // Re-serialise with attempts+1 so the incremented counter survives the requeue
        // (beanstalkd's native `release` cannot mutate the job body, so we delete + put).
        $wire = json_decode($raw->getData());
        if ($wire instanceof stdClass) {
            $wire->attempts = (isset($wire->attempts) ? (int) $wire->attempts : 0) + 1;
            $newPayload     = json_encode($wire, JSON_THROW_ON_ERROR);
        } else {
            $newPayload = $raw->getData();
        }

        $delay = max(0, $delaySeconds ?? 0);

        $connection->delete($raw);
        $connection->useTube(new TubeName($lease->envelope->queue));
        $connection->put($newPayload, $this->priority, $delay, $this->ttr);

        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        $connection = $this->connection;
        if (! $connection instanceof Pheanstalk) {
            return false;
        }

        $raw = $lease->envelope->raw;
        if (! $raw instanceof Job) {
            return false;
        }

        // No retry: route to beanstalkd's native buried list (its dead-letter facility).
        $connection->bury($raw, $this->priority);

        return true;
    }

    /**
     * No-op for beanstalkd: the server reclaims a reserved job automatically once its
     * per-job TTR elapses (the job returns to the ready state without operator action),
     * so there is no external in-flight set for a reaper to scan. Always returns 0.
     */
    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        return 0;
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

    private function requireConnection(): Pheanstalk
    {
        $connection = $this->connection;
        if (! $connection instanceof Pheanstalk) {
            throw new RuntimeException('Beanstalkd connection not available');
        }

        return $connection;
    }

    private function resolveTtr(?int $timeout): int
    {
        if ($timeout !== null && $timeout > 0) {
            return $timeout;
        }

        $configured = config('Jobs')->jobTimeout;
        if ($configured > 0) {
            return $configured;
        }

        return $this->ttr;
    }
}
