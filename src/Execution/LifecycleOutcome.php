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
 * Aggregated outcome of the full job lifecycle (including retries).
 * finalResult: last ExecutionResult (successful or failing).
 * attempts: total attempts performed (>=1).
 * finalFailure: true if all attempts failed.
 * requeued: flag indicating if job was requeued by a completion strategy (future use for queue workers).
 * attemptsMeta: lightweight per-attempt telemetry (attempt, success, error, duration seconds).
 */
class LifecycleOutcome
{
    public function __construct(
        public ExecutionResult $finalResult,
        public int $attempts,
        public bool $finalFailure,
        public bool $requeued,
        public array $attemptsMeta = [],
    ) {
    }
}
