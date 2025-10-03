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
 * Immutable data transfer object representing the outcome of a single handler execution.
 * success: whether the handler completed logically successful.
 * output: normalized string output (captured buffer + handler return) when success.
 * error: error message when not successful.
 * startedAt / endedAt: high-resolution timestamps (microtime true) in seconds.
 * handlerClass: resolved handler class name (if available) for observability.
 */
class ExecutionResult
{
    public function __construct(
        public bool $success,
        public ?string $output,
        public ?string $error,
        public float $startedAt,
        public float $endedAt,
        public ?string $handlerClass = null,
    ) {
    }

    public function durationSeconds(): float
    {
        return max(0.0, $this->endedAt - $this->startedAt);
    }
}
