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

use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;

/**
 * Resolves a handler key (e.g. 'command') to a {@see JobHandlerInterface} instance using
 * Config\Jobs::$handlers, and enforces the per-queue handler allowlist
 * (Config\Jobs::$queueHandlers).
 */
final class HandlerRegistry
{
    /**
     * @var array<string, class-string>
     */
    private array $map;

    /**
     * @var array<string, list<string>>
     */
    private array $queueHandlers;

    public function __construct(Jobs $config)
    {
        $this->map           = $config->handlers;
        $this->queueHandlers = $config->queueHandlers;
    }

    public function resolve(string $key): JobHandlerInterface
    {
        $class = $this->map[$key] ?? null;
        if ($class === null || ! is_a($class, JobHandlerInterface::class, true)) {
            throw JobException::forInvalidJob($key);
        }

        return new $class();
    }

    /**
     * Resolve a handler for a specific queue, enforcing the per-queue allowlist.
     * A queue absent from $queueHandlers (or with no entry) imposes no restriction.
     */
    public function resolveForQueue(string $key, string $queue): JobHandlerInterface
    {
        $allowed = $this->queueHandlers[$queue] ?? null;
        if ($allowed !== null && ! in_array($key, $allowed, true)) {
            throw JobException::validationError("Handler '{$key}' is not allowed on queue '{$queue}'.");
        }

        return $this->resolve($key);
    }
}
