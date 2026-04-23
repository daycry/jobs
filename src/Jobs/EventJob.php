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
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Traits\InteractsWithCurrentJob;

/**
 * Triggers a CodeIgniter event.
 * Payload: ['name' => string, 'data' => array(optional)].
 */
class EventJob extends Job implements JobInterface
{
    use InteractsWithCurrentJob;

    public function handle(mixed $payload): mixed
    {
        if (! is_array($payload) || empty($payload['name']) || ! is_string($payload['name'])) {
            throw JobException::validationError('EventJob payload must be an array with a string "name" key.');
        }

        return Events::trigger($payload['name'], $payload['data'] ?? []);
    }
}
