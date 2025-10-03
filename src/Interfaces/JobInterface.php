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

namespace Daycry\Jobs\Interfaces;

use Daycry\Jobs\Job;

/**
 * Contract for job handlers.
 *
 * Lifecycle methods:
 *  - beforeRun(): receive mutable Job instance, allow adjustments (payload, metadata) and return it.
 *  - handle(): main business logic. Return value will be normalized (scalars/arrays -> string|json). Throw to signal failure.
 *  - afterRun(): final hook executed regardless of logical success (exceptions are caught above this layer).
 */
interface JobInterface
{
    /**
     * Execute job logic.
     * Return simple value (string|array|scalar|null) to be normalized.
     * Throwing any exception will produce a failing ExecutionResult upstream.
     */
    public function handle(mixed $payload): mixed;

    public function beforeRun(Job $job): Job;

    public function afterRun(Job $job): Job;
}
