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

use CodeIgniter\Events\Events;
use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;

/**
 * Triggers a CodeIgniter event.
 * Payload: ['name' => string, 'data' => array(optional)].
 */
class EventJob extends Job implements JobInterface
{
    public function handle(mixed $payload): mixed
    {
        return Events::trigger($payload['name'], $payload['data'] ?? []);
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
