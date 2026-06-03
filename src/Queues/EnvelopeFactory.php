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
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;
use stdClass;

/**
 * Builds the canonical wire payload shared by every {@see QueueBackend} from a
 * {@see JobDefinition}. Centralising this guarantees all backends store the same
 * structure so a message enqueued via one path can be consumed via another.
 *
 * Wire shape (JSON object stored by the backend):
 *  { job, payload, queue, priority, maxRetries, attempts, name, identifier, schedule, _sig }
 *
 * The `_sig` HMAC is computed over the IMMUTABLE identity fields only (see
 * {@see canonicalJson()}); the mutable `attempts`/`schedule` are excluded so the signature
 * survives a requeue (Redis re-serialises with attempts+1). The worker verifies `_sig`
 * after fetch to reject tampered/forged messages.
 */
final class EnvelopeFactory
{
    /**
     * Canonical wire object for a definition about to be enqueued, signed in place.
     */
    public static function toWire(JobDefinition $definition, string $identifier, ?EnvelopeSigner $signer = null): stdClass
    {
        $wire             = new stdClass();
        $wire->job        = $definition->handler;
        $wire->payload    = $definition->payload;
        $wire->queue      = $definition->queue ?? 'default';
        $wire->priority   = $definition->priority;
        $wire->maxRetries = $definition->maxRetries;
        $wire->attempts   = 0;
        $wire->name       = $definition->name;
        $wire->identifier = $identifier;
        $wire->schedule   = $definition->scheduledAt instanceof DateTimeImmutable
            ? $definition->scheduledAt->format('Y-m-d H:i:s')
            : null;

        $signer ??= new EnvelopeSigner();
        $wire->_sig = $signer->sign(self::canonicalJson($wire));

        return $wire;
    }

    /**
     * Deterministic JSON over the immutable identity fields used for signing/verification.
     * Excludes `attempts` and `schedule` (mutable across requeues) and `_sig` itself.
     */
    public static function canonicalJson(object $wire): string
    {
        return json_encode([
            'job'        => $wire->job ?? null,
            'payload'    => $wire->payload ?? null,
            'queue'      => $wire->queue ?? null,
            'priority'   => $wire->priority ?? null,
            'maxRetries' => $wire->maxRetries ?? null,
            'name'       => $wire->name ?? null,
            'identifier' => $wire->identifier ?? null,
        ], JSON_THROW_ON_ERROR);
    }
}
