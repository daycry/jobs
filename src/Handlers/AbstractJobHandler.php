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
 * Base handler providing no-op lifecycle hooks so concrete handlers only need handle().
 */
abstract class AbstractJobHandler implements JobHandlerInterface
{
    public function beforeRun(JobContext $ctx): void
    {
    }

    public function afterRun(JobContext $ctx, ExecutionResult $result): void
    {
    }
}
