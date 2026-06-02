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

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Daycry\Jobs\Entities\Queue as QueueEntity;
use Daycry\Jobs\Models\QueueModel;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use Daycry\Jobs\V2\JobDefinition;
use stdClass;

/**
 * Database-backed queue implementing the v3 {@see QueueBackend} contract.
 *
 * Reuses the proven atomic claim of {@see QueueModel::reserveJob()} (FOR UPDATE SKIP LOCKED
 * with optimistic fallback) and adds the v3 lease semantics:
 *  - fetch() stamps reserved_at + a per-worker owner token and returns a JobLease.
 *  - nack() requeues IN PLACE (same row/id, attempts+1, available_at = now + delay) — no orphan rows.
 *  - reapExpired() returns rows whose lease exceeded the visibility timeout to 'pending'.
 *
 * Delivery is at-least-once; handlers should be idempotent.
 */
final class DatabaseBackend implements QueueBackend
{
    private const BACKEND = 'database';

    public function enqueue(JobDefinition $definition): string
    {
        $identifier = bin2hex(random_bytes(8));
        $wire       = EnvelopeFactory::toWire($definition, $identifier);
        $tz         = new DateTimeZone(config('App')->appTimezone);
        $schedule   = $definition->scheduledAt instanceof DateTimeImmutable
            ? DateTime::createFromInterface($definition->scheduledAt)
            : new DateTime('now', $tz);

        $entity              = new QueueEntity();
        $entity->identifier  = $identifier;
        $entity->queue       = $definition->queue ?? 'default';
        $entity->payload     = json_encode($wire);
        $entity->priority    = $definition->priority;
        $entity->schedule    = $schedule->format('Y-m-d H:i:s');
        $entity->status      = 'pending';
        $entity->max_retries = $definition->maxRetries;
        $entity->attempts    = 0;

        (new QueueModel())->insert($entity);

        return $identifier;
    }

    public function fetch(string $queue): ?JobLease
    {
        $visibilityTimeout = (int) (config('Jobs')->databaseVisibilityTimeout ?? 300);
        $owner             = bin2hex(random_bytes(16));

        $row = (new QueueModel())->reserveJob($queue, $owner);
        if (! $row instanceof QueueEntity) {
            return null;
        }

        $decoded = json_decode($row->payload ?? '{}');
        $payload = $decoded instanceof stdClass ? $decoded : new stdClass();

        $envelope = new JobEnvelope(
            id: (string) $row->identifier,
            queue: (string) $row->queue,
            payload: $payload,
            name: isset($payload->name) && is_string($payload->name) ? $payload->name : null,
            attempts: (int) $row->attempts,
            priority: isset($payload->priority) ? (int) $payload->priority : null,
            meta: ['backend' => self::BACKEND, 'entity_id' => $row->id, 'status' => $row->status],
            raw: $row,
        );

        return JobLease::withRelativeExpiry($envelope, (string) $row->id, $owner, $visibilityTimeout, self::BACKEND);
    }

    public function ack(JobLease $lease): bool
    {
        return (new QueueModel())->markStatus((int) $lease->token, 'completed');
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        return (new QueueModel())->requeueInPlace((int) $lease->token, max(0, $delaySeconds ?? 0));
    }

    public function abandon(JobLease $lease): bool
    {
        // No row-level native DLQ: mark 'failed' so the record is retained for audit but never
        // re-fetched. The runtime forwards the payload to the configured DeadLetterQueue (if any)
        // before calling abandon().
        return (new QueueModel())->markStatus((int) $lease->token, 'failed');
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        return (new QueueModel())->reapStuck($queue, $visibilityTimeout);
    }
}
