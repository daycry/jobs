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

use DateTimeImmutable;

/**
 * Represents a *lease* on an in-flight queue message (v3 canonical).
 *
 * Every {@see QueueBackend} produces a JobLease at fetch() time and consumes one at
 * ack()/nack()/abandon() time, so the backend never has to remember which message the
 * worker is currently processing.
 *
 *  - token: opaque backend identifier needed to ack/nack later (primary key for the DB
 *    backend, raw payload for the Redis processing list, LockToken for Service Bus, jobId
 *    for Beanstalk).
 *  - ownerToken: a random token minted by the worker at fetch() and stored alongside the
 *    in-flight record. The reaper only reclaims leases that have expired; ack/nack verify
 *    ownership so a reaped-then-reassigned message cannot be acked by the previous owner.
 *  - expiresAt: visibility deadline. Past this time the backend may redeliver. A long-running
 *    worker calls {@see renew()} (and the backend re-stamps the record) to extend it.
 */
final readonly class JobLease
{
    public function __construct(
        public JobEnvelope $envelope,
        public string $token,
        public string $ownerToken,
        public DateTimeImmutable $expiresAt,
        public string $backend,
    ) {
    }

    /**
     * Convenience factory computing the deadline as "now + N seconds".
     */
    public static function withRelativeExpiry(
        JobEnvelope $envelope,
        string $token,
        string $ownerToken,
        int $secondsFromNow,
        string $backend,
    ): self {
        $expiry = (new DateTimeImmutable())->modify('+' . max(1, $secondsFromNow) . ' seconds');

        return new self($envelope, $token, $ownerToken, $expiry, $backend);
    }

    /**
     * Has the lease already expired? Workers that detect this should NOT ack — the backend
     * may already have redelivered the message to another consumer.
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $now > $this->expiresAt;
    }

    /**
     * Return a copy with the visibility deadline extended (token/owner preserved). The backend
     * is responsible for re-stamping its in-flight record so the reaper sees the fresh deadline.
     */
    public function renew(int $secondsFromNow): self
    {
        $expiry = (new DateTimeImmutable())->modify('+' . max(1, $secondsFromNow) . ' seconds');

        return new self($this->envelope, $this->token, $this->ownerToken, $expiry, $this->backend);
    }
}
