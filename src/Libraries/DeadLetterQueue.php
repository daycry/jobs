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

use Daycry\Jobs\Job;
use Throwable;

/**
 * Dead Letter Queue manager.
 * Handles jobs that have permanently failed after exhausting all retries.
 */
class DeadLetterQueue
{
    /**
     * Move a job to the dead letter queue.
     *
     * @param Job    $job      Failed job
     * @param string $reason   Failure reason
     * @param int    $attempts Number of attempts made
     */
    public function store(Job $job, string $reason, int $attempts): void
    {
        $config  = ConfigCache::get();
        $dlqName = $config->deadLetterQueue;

        if (! $dlqName) {
            return; // DLQ disabled
        }

        // Add metadata about the failure
        $metadata = [
            'dlq_reason'     => $reason,
            'dlq_timestamp'  => date('Y-m-d H:i:s'),
            'dlq_attempts'   => $attempts,
            'original_queue' => $job->getQueue(),
        ];

        // Create a new job instance for DLQ
        $dlqJob = clone $job;
        $dlqJob->queue($dlqName);

        // Store metadata in payload if possible
        $payload = $dlqJob->getPayload();
        if (is_array($payload)) {
            $payload['_dlq_metadata'] = $metadata;
            $dlqJob->setPayload($payload);
        }

        // Push to DLQ
        try {
            $dlqJob->push();
            log_message('info', "Job {$job->getName()} moved to DLQ after {$attempts} attempts. Reason: {$reason}");
        } catch (Throwable $e) {
            log_message('error', "Failed to store job in DLQ: {$e->getMessage()}");
        }
    }

    /**
     * Get statistics about dead letter queue.
     */
    public function getStats(): array
    {
        $config  = ConfigCache::get();
        $dlqName = $config->deadLetterQueue;

        if (! $dlqName) {
            return ['enabled' => false];
        }

        // This would require queue backend support to count jobs
        return [
            'enabled' => true,
            'queue'   => $dlqName,
        ];
    }
}
