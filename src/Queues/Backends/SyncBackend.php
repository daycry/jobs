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

use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Execution\JobRuntime;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use Daycry\Jobs\V2\JobDefinition;
use Exception;

/**
 * Synchronous, in-process queue backend implementing the v3 {@see QueueBackend} contract.
 *
 * Unlike persistent backends, SyncBackend does NOT enqueue: enqueue() runs the job inline
 * through the v3 {@see JobRuntime} and returns a synthetic id. Useful for tests and for
 * local/CLI flows where a real broker is undesirable.
 *
 * Because work happens at enqueue() time, there is nothing to pull: fetch() always returns
 * null, the lease verbs are no-ops returning true, and reapExpired() recovers nothing.
 */
final class SyncBackend implements QueueBackend
{
    /**
     * Execute the definition inline and return a synthetic, non-persistent id.
     *
     * @throws Exception When random_bytes() cannot gather sufficient entropy.
     */
    public function enqueue(JobDefinition $definition): string
    {
        $context = new JobContext(
            payload: $definition->payload,
            name: $definition->name,
            queue: $definition->queue ?? 'default',
        );

        (new JobRuntime())->run($definition, $context);

        return 'sync-' . bin2hex(random_bytes(6));
    }

    /**
     * Synchronous execution has no pull side; there is never a message to lease.
     */
    public function fetch(string $queue): ?JobLease
    {
        return null;
    }

    public function ack(JobLease $lease): bool
    {
        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        return true;
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        return 0;
    }
}
