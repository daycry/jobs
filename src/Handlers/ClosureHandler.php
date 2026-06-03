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

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Executes a user-provided callable carried in the payload.
 *
 * Only works in-process (Sync backend); closures do not survive serialization to a
 * remote queue, so enqueueing a ClosureHandler to a persistent backend is rejected
 * upstream by the builder/runtime.
 */
final class ClosureHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        if (! is_callable($payload)) {
            throw JobException::validationError('ClosureHandler payload must be callable (Closure or object with __invoke).');
        }

        return $payload();
    }
}
