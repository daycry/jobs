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

use Daycry\Jobs\Definition\JobDefinition;

/**
 * Unified v3 queue backend contract with lease semantics.
 *
 * Replaces the legacy split between {@see \Daycry\Jobs\Interfaces\QueueInterface} and
 * {@see \Daycry\Jobs\Interfaces\WorkerInterface} (which coupled the two sides through the
 * backend's instance state). The contract is stateless w.r.t. the in-flight message: the
 * worker holds a {@see JobLease} and passes it back to ack/nack/abandon.
 *
 * Delivery semantics are at-least-once for every persistent backend, recovered by
 * {@see reapExpired()}; handlers should therefore be idempotent.
 */
interface QueueBackend
{
    /**
     * Persist the definition into the queue. Returns the backend-assigned id.
     */
    public function enqueue(JobDefinition $definition): string;

    /**
     * Lease one ready message, or null when the queue is empty (after any blocking timeout
     * configured at the backend level). The lease carries a fresh owner token and a
     * visibility deadline.
     */
    public function fetch(string $queue): ?JobLease;

    /**
     * Mark the leased message as successfully processed; the backend removes it permanently.
     */
    public function ack(JobLease $lease): bool;

    /**
     * Indicate that processing failed and the message should be redelivered, optionally after
     * $delaySeconds (used to apply retry backoff). Persistent backends requeue in place.
     */
    public function nack(JobLease $lease, ?int $delaySeconds = null): bool;

    /**
     * Stop holding the lease without retrying (unprocessable message). For backends with a
     * native dead-letter facility this routes there; otherwise it is equivalent to ack().
     */
    public function abandon(JobLease $lease): bool;

    /**
     * Reclaim messages whose lease expired (worker crashed/stalled) so they become eligible
     * again. Returns the number of messages recovered. Invoked periodically by the reaper.
     */
    public function reapExpired(string $queue, int $visibilityTimeout): int;
}
