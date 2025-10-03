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

namespace Daycry\Jobs\Execution;

/**
 * Immutable execution context describing orchestration parameters for a job lifecycle run.
 * source: 'cron' | 'queue' origin.
 * maxRetries: maximum retry attempts (excluding first attempt).
 * notifyOnSuccess / notifyOnFailure: enable notification channel dispatch.
 * singleInstance: enforce single concurrent execution for the job definition.
 * queueName / queueWorker: present when executing via a queue backend.
 * retryConfig: raw policy configuration (strategy, base, multiplier, jitter, max).
 * eventsEnabled: allows disabling event emission (future extension).
 * meta: free-form correlation/tracing metadata.
 */
class ExecutionContext
{
    public function __construct(
        public string $source,                 // 'cron' | 'queue'
        public int $maxRetries,
        public bool $notifyOnSuccess,
        public bool $notifyOnFailure,
        public bool $singleInstance,
        public ?string $queueName = null,
        public ?object $queueWorker = null,
        public array $retryConfig = [],        // strategy, base, multiplier, jitter, max
        public bool $eventsEnabled = true,
        public array $meta = [],               // traceId, correlationId, etc.
    ) {
    }
}
