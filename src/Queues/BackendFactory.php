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

use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\QueueException;

/**
 * Resolves a {@see QueueBackend} from configuration (Config\Jobs::$backends), defaulting to
 * the configured worker name. Replaces the v1 QueueManager (which returned the legacy
 * QueueInterface+WorkerInterface split).
 */
final class BackendFactory
{
    public static function make(Jobs $config, ?string $name = null): QueueBackend
    {
        $name ??= $config->worker;
        $map = $config->backends;

        if (! array_key_exists($name, $map)) {
            throw QueueException::forInvalidWorker($name);
        }

        $class = $map[$name];
        if (! is_a($class, QueueBackend::class, true)) {
            throw QueueException::forInvalidWorker($name);
        }

        return new $class();
    }
}
