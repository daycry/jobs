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

namespace Daycry\Jobs\Jobs;

use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Traits\InteractsWithCurrentJob;

/**
 * Executes a user-provided Closure (callable) stored in the payload.
 * Payload MUST be an invokable (Closure or object with __invoke()).
 */
class ClosureJob extends Job implements JobInterface
{
    use InteractsWithCurrentJob;

    public function handle(mixed $payload): mixed
    {
        return $payload->__invoke();
    }
}
