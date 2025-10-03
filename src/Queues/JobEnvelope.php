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

use DateTimeInterface;
use Daycry\Jobs\Job;

/**
 * Normalized queue message representation independent of backend implementation.
 *
 * This first iteration is introduced for DatabaseQueue only; other workers will migrate progressively.
 *
 * Fields:
 *  - id: Backend job identifier (string form)
 *  - queue: Queue/tube name
 *  - payload: Original serialized job payload decoded (stdClass|array)
 *  - attempts: Current attempt count (backend or synthetic)
 *  - priority: Normalized priority if available
 *  - scheduledAt / availableAt: Timestamps when job was scheduled / becomes available
 *  - meta: Arbitrary extra metadata (headers, raw status, etc.)
 *  - raw: The backend native record/object for edge operations
 */
final class JobEnvelope
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly array|object|null $payload,
        public readonly int $attempts = 0,
        public readonly ?int $priority = null,
        public readonly ?DateTimeInterface $scheduledAt = null,
        public readonly ?DateTimeInterface $availableAt = null,
        public readonly ?DateTimeInterface $createdAt = null,
        public readonly array $meta = [],
        public readonly mixed $raw = null,
    ) {
    }

    /**
     * Create from a decoded backend payload plus raw reference.
     */
    public static function fromDecoded(
        string $id,
        string $queue,
        array|object|null $decoded,
        int $attempts = 0,
        ?int $priority = null,
        ?DateTimeInterface $scheduledAt = null,
        ?DateTimeInterface $availableAt = null,
        ?DateTimeInterface $createdAt = null,
        array $meta = [],
        mixed $raw = null,
    ): self {
        return new self($id, $queue, $decoded, $attempts, $priority, $scheduledAt, $availableAt, $createdAt, $meta, $raw);
    }

    /**
     * Create directly from a Job domain object (minimal meta)
     */
    public static function fromJob(Job $job, array $meta = []): self
    {
        $payload = $job->toObject();

        return new self(
            id: $payload->identifier ?? $job->getName() . '-' . bin2hex(random_bytes(4)),
            queue: $payload->queue ?? 'default',
            payload: $payload,
            attempts: $job->getAttempt(),
            priority: $payload->priority ?? null,
            scheduledAt: $payload->schedule ?? null,
            availableAt: null,
            createdAt: $payload->createdAt ?? ($payload->schedule ?? null),
            meta: $meta,
            raw: $job,
        );
    }
}
