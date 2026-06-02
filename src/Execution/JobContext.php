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

namespace Daycry\Jobs\Execution;

/**
 * Immutable execution context passed to a job handler.
 *
 * Decouples handlers from the job definition/builder: a handler only ever sees the
 * payload it must process plus read-only metadata about the current run. This is the
 * v3 replacement for passing the whole mutable {@see \Daycry\Jobs\Job} into handlers.
 */
final readonly class JobContext
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public mixed $payload,
        public ?string $name = null,
        public ?string $queue = null,
        public int $attempt = 1,
        public array $meta = [],
    ) {
    }

    public static function fromPayload(mixed $payload): self
    {
        return new self(payload: $payload);
    }
}
