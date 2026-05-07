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

use Daycry\Jobs\V2\JobDefinition;

/**
 * Unified v2 queue backend interface.
 *
 * Replaces the legacy split between {@see \Daycry\Jobs\Interfaces\QueueInterface} and
 * {@see \Daycry\Jobs\Interfaces\WorkerInterface}, which was awkward because the two sides
 * carried implicit coupling via {@see \Daycry\Jobs\Queues\BaseQueue}'s instance state
 * (current job, last raw payload, etc.).
 *
 * The v2 contract is:
 *  - enqueue() takes a definition and returns a backend-assigned id (no shared state).
 *  - fetch() returns a JobLease the caller is responsible for, or null if the queue is empty.
 *  - ack() / nack() / abandon() act on the lease — the backend never has to remember which
 *    message the worker is currently processing.
 *
 * Workers can hold multiple leases concurrently (e.g., a future batch-fetch implementation),
 * which is impossible with the v1 single-slot WorkerInterface.
 */
interface QueueBackend
{
    /**
     * Persist the definition into the queue. Returns the id assigned by the backend.
     */
    public function enqueue(JobDefinition $definition): string;

    /**
     * Lease one ready message. Returns null when the queue is empty (after any blocking
     * timeout configured at the backend level).
     */
    public function fetch(string $queue): ?JobLease;

    /**
     * Mark the leased message as successfully processed. The backend removes it permanently.
     */
    public function ack(JobLease $lease): bool;

    /**
     * Indicate that processing failed and the message should be redelivered.
     * The backend SHOULD requeue the message; if a delay is supplied, it MAY honour
     * it (Beanstalk does, Redis does not natively — backends document their behaviour).
     */
    public function nack(JobLease $lease, ?int $delaySeconds = null): bool;

    /**
     * Stop holding the lease without retrying. Used when the message is unprocessable
     * (e.g., bad payload) and forwarding to the platform's dead-letter facility is the
     * desired behaviour. For backends without a native DLQ, abandon() is equivalent to ack().
     */
    public function abandon(JobLease $lease): bool;
}
