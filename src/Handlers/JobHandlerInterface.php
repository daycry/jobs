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

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\JobContext;

/**
 * Contract for v3 job handlers.
 *
 * A handler contains ONLY business logic; unlike the v1 design it does NOT extend the
 * job builder and never carries scheduling/queue state. It receives an immutable
 * {@see JobContext} and returns a value that the runtime normalizes (scalar/array ->
 * string|json). Throwing any Throwable signals failure to the runtime.
 */
interface JobHandlerInterface
{
    /**
     * Main business logic. Return value is normalized upstream.
     * Throwing any Throwable signals failure.
     */
    public function handle(JobContext $ctx): mixed;

    /**
     * Optional hook executed before handle().
     */
    public function beforeRun(JobContext $ctx): void;

    /**
     * Optional hook executed after handle(), regardless of logical success.
     */
    public function afterRun(JobContext $ctx, ExecutionResult $result): void;
}
