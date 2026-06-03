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

use CodeIgniter\Events\Events;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Triggers a CodeIgniter event named in the payload.
 *
 * Secure-by-default: only events listed in Config\Jobs::$allowedEvents may be fired,
 * so a tampered/untrusted queue message cannot dispatch arbitrary application events.
 * An empty allowlist denies everything.
 *
 * Payload: ['name' => string, 'data' => array(optional)].
 */
final class EventHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        if (! is_array($payload) || ! isset($payload['name']) || ! is_string($payload['name']) || $payload['name'] === '') {
            throw JobException::validationError('EventHandler payload must be an array with a string "name" key.');
        }

        $allowed = config('Jobs')->allowedEvents ?? [];
        if (! in_array($payload['name'], $allowed, true)) {
            throw JobException::forEventNotAllowed($payload['name']);
        }

        $data = $payload['data'] ?? [];

        return Events::trigger($payload['name'], is_array($data) ? $data : [$data]);
    }
}
