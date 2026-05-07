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

namespace Daycry\Jobs\V2\Queues;

use DateTimeImmutable;
use Daycry\Jobs\Queues\JobEnvelope;

/**
 * Represents a *lease* on an in-flight queue message.
 *
 * Every backend that participates in v2 produces a JobLease at fetch() time and consumes
 * one at ack()/nack()/abandon() time. The token is opaque to the framework and is the
 * key the backend needs to identify the same message later (LockToken for Service Bus,
 * raw payload for Redis processing list, primary key for DatabaseQueue, jobId for Beanstalk).
 *
 * Holding a lease is the contract for "I am responsible for this message". A worker that
 * crashes before acking the lease lets it expire so another worker can pick it up; a
 * worker that explicitly nacks asks the backend to redeliver immediately.
 */
final class JobLease
{
    /**
     * @param JobEnvelope       $envelope  The message payload + metadata extracted from the backend.
     * @param string            $token     Opaque backend identifier needed to ack/nack later.
     * @param DateTimeImmutable $expiresAt Visibility deadline — past this time the backend may redeliver.
     * @param string            $backend   Backend name ('redis', 'database', 'servicebus', 'beanstalk', 'sync').
     */
    public function __construct(
        public readonly JobEnvelope $envelope,
        public readonly string $token,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $backend,
    ) {
    }

    /**
     * Convenience factory for backends that compute the deadline as "now + N seconds".
     */
    public static function withRelativeExpiry(JobEnvelope $envelope, string $token, int $secondsFromNow, string $backend): self
    {
        $expiry = (new DateTimeImmutable())->modify('+' . max(1, $secondsFromNow) . ' seconds');

        return new self($envelope, $token, $expiry, $backend);
    }

    /**
     * Has the lease already expired? Workers that detect this should NOT ack — the
     * broker may already have redelivered the message to another consumer.
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $now > $this->expiresAt;
    }
}
