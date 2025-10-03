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

/**
 * Executes a shell command using exec().
 * Payload: string command to execute. Returns captured output array.
 * NOTE: Use cautiously; ensure payload is trusted to avoid command injection.
 */
class ShellJob extends Job implements JobInterface
{
    public function handle(mixed $payload): mixed
    {
        exec($payload, $output);

        return $output;
    }

    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}
