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

namespace Daycry\Jobs;

use Daycry\Jobs\Definition\JobBuilder;
use Daycry\Jobs\Queues\BackendFactory;
use Daycry\Jobs\Queues\QueueBackend;

/**
 * Static entry point (facade) for the v3 API.
 *
 * {@see define()} opens a fluent {@see JobBuilder}; {@see backend()} resolves a configured
 * {@see QueueBackend} directly. This is the public surface that replaces the v1 mutable
 * {@see Job} builder as the primary way to describe and dispatch work.
 */
final class Jobs
{
    /**
     * Begin describing a job with the fluent builder.
     */
    public static function define(string $handler, mixed $payload = null): JobBuilder
    {
        return new JobBuilder($handler, $payload);
    }

    /**
     * Resolve a configured queue backend (or the default worker when $name is null).
     */
    public static function backend(?string $name = null): QueueBackend
    {
        return BackendFactory::make((new JobBuilder(''))->config(), $name);
    }
}
