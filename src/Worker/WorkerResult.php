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

namespace Daycry\Jobs\Worker;

/**
 * Outcome of a single {@see QueueWorker::processOnce()} cycle.
 *
 * status is one of: 'empty', 'acked', 'requeued', 'dead-lettered', 'rejected',
 * 'skipped-idempotent'.
 */
final readonly class WorkerResult
{
    public function __construct(
        public string $status,
        public int $attempts = 0,
        public ?string $error = null,
    ) {
    }
}
