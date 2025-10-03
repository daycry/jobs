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

use Daycry\Jobs\Job;

class QueueCompletionStrategy implements CompletionStrategy
{
    public function onSuccess(Job $job, ExecutionResult $result, ExecutionContext $ctx): void
    {
        if ($ctx->queueWorker) {
            // removeJob recreate=false
            $ctx->queueWorker->removeJob($job, false);
        }
    }

    public function onFailure(Job $job, ExecutionResult $result, ExecutionContext $ctx, int $attempt): void
    {
        if ($ctx->queueWorker) {
            // Para ahora: siempre recreate=true → en el futuro podríamos limitar por maxRetries
            $ctx->queueWorker->removeJob($job, true);
        }
    }
}
