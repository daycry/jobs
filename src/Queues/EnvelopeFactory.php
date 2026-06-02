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

namespace Daycry\Jobs\Queues;

use DateTimeImmutable;
use Daycry\Jobs\V2\JobDefinition;
use stdClass;

/**
 * Builds the canonical wire payload shared by every {@see QueueBackend} from a
 * {@see JobDefinition}. Centralising this guarantees all backends store the same
 * structure so a message enqueued via one path can be consumed via another.
 *
 * Wire shape (JSON object stored by the backend):
 *  { job, payload, queue, priority, maxRetries, attempts, name, identifier, schedule, idempotencyKey }
 */
final class EnvelopeFactory
{
    /**
     * Canonical wire object for a definition about to be enqueued.
     */
    public static function toWire(JobDefinition $definition, string $identifier): stdClass
    {
        $wire                 = new stdClass();
        $wire->job            = $definition->handler;
        $wire->payload        = $definition->payload;
        $wire->queue          = $definition->queue ?? 'default';
        $wire->priority       = $definition->priority;
        $wire->maxRetries     = $definition->maxRetries;
        $wire->attempts       = 0;
        $wire->name           = $definition->name;
        $wire->identifier     = $identifier;
        $wire->schedule       = $definition->scheduledAt instanceof DateTimeImmutable
            ? $definition->scheduledAt->format('Y-m-d H:i:s')
            : null;

        return $wire;
    }
}
