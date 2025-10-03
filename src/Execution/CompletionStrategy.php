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

interface CompletionStrategy
{
    public function onSuccess(Job $job, ExecutionResult $result, ExecutionContext $ctx): void;

    public function onFailure(Job $job, ExecutionResult $result, ExecutionContext $ctx, int $attempt): void;
}
