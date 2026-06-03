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

namespace Daycry\Jobs\Libraries;

use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\BackendFactory;
use Throwable;

/**
 * Dead Letter Queue manager.
 * Handles jobs that have permanently failed after exhausting all retries.
 */
class DeadLetterQueue
{
    /**
     * Route a permanently-failed job's payload to the configured dead letter queue.
     *
     * @param mixed  $payload  The failed job payload (re-enqueued onto the DLQ for inspection).
     * @param string $handler  Handler key of the failed job.
     * @param string $reason   Failure reason (recorded in the DLQ payload metadata).
     * @param int    $attempts Number of attempts made before giving up.
     *
     * @return bool true when the payload was successfully persisted to the DLQ; false otherwise
     *              (DLQ disabled in config or enqueue to backend failed). Callers MUST act on a
     *              false return value to avoid silent job loss.
     */
    public function store(mixed $payload, string $handler, string $reason, int $attempts): bool
    {
        $config  = ConfigCache::get();
        $dlqName = $config->deadLetterQueue ?? null;

        if ($dlqName === null || $dlqName === '') {
            log_message('critical', "Job '{$handler}' permanently failed after {$attempts} attempts but DLQ is not configured — caller must decide whether to drop or requeue. Reason: {$reason}");

            return false;
        }

        $metadata = [
            'dlq_reason'    => $reason,
            'dlq_timestamp' => date('Y-m-d H:i:s'),
            'dlq_attempts'  => $attempts,
        ];

        $dlqPayload = $payload;
        if (is_array($dlqPayload)) {
            $dlqPayload['_dlq_metadata'] = $metadata;
        }

        $definition = new JobDefinition(
            handler: $handler,
            payload: $dlqPayload,
            queue: $dlqName,
            meta: $metadata,
        );

        try {
            BackendFactory::make($config)->enqueue($definition);
            log_message('info', "Job '{$handler}' moved to DLQ after {$attempts} attempts. Reason: {$reason}");

            return true;
        } catch (Throwable $e) {
            log_message('critical', "Failed to store job '{$handler}' in DLQ: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get statistics about dead letter queue.
     *
     * @return array{enabled: bool, queue?: string}
     */
    public function getStats(): array
    {
        $config  = ConfigCache::get();
        $dlqName = $config->deadLetterQueue;

        if ($dlqName === null || $dlqName === '') {
            return ['enabled' => false];
        }

        // This would require queue backend support to count jobs
        return [
            'enabled' => true,
            'queue'   => $dlqName,
        ];
    }
}
