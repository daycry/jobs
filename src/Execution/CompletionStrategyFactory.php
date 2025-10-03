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

class CompletionStrategyFactory
{
    public function for(ExecutionContext $ctx): CompletionStrategy
    {
        return $ctx->source === 'queue'
            ? new QueueCompletionStrategy()
            : new CronCompletionStrategy();
    }
}
