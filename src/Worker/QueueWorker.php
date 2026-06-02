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

use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Execution\IdempotencyGuard;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Execution\JobRuntime;
use Daycry\Jobs\Execution\RetryPolicy;
use Daycry\Jobs\Execution\RetryPolicyFixed;
use Daycry\Jobs\Metrics\Metrics;
use Daycry\Jobs\Metrics\MetricsCollectorInterface;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\QueueBackend;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;
use Daycry\Jobs\V2\JobDefinition;
use stdClass;

/**
 * Drives a single queue message through the v3 pipeline:
 *   fetch -> verify signature -> idempotency guard -> run ONE attempt -> ack / nack(backoff) / abandon.
 *
 * The retry decision lives HERE (not in the runtime): a job runs at most maxRetries + 1 times.
 * On failure with retries left it nacks with a backoff delay (the backend requeues); when retries
 * are exhausted it abandons (dead-letter). This is the end-to-end fix for the legacy double-retry
 * (#4) and brings backoff to the requeue (#13). Signature verification rejects tampered/forged
 * messages (#1). Delivery is at-least-once, so opt-in idempotency (#5) is honoured when present.
 */
final class QueueWorker
{
    private readonly Jobs $config;

    public function __construct(
        private readonly QueueBackend $backend,
        private readonly ?JobRuntime $runtime = null,
        private readonly ?EnvelopeSigner $signer = null,
        private readonly ?IdempotencyGuard $idempotency = null,
        private readonly ?RetryPolicy $retryPolicy = null,
        private readonly ?MetricsCollectorInterface $metrics = null,
        ?Jobs $config = null,
    ) {
        $this->config = $config ?? config('Jobs');
    }

    public function processOnce(string $queue): WorkerResult
    {
        $metrics = $this->metrics ?? Metrics::get();

        $lease = $this->backend->fetch($queue);
        if ($lease === null) {
            return new WorkerResult('empty');
        }

        $metrics?->increment('jobs_fetched', 1, ['queue' => $queue]);

        $wire = $lease->envelope->payload;
        if (! $wire instanceof stdClass) {
            $this->backend->abandon($lease);

            return new WorkerResult('rejected', error: 'invalid payload');
        }

        // Signature verification — reject tampered/forged messages.
        $signer = $this->signer ?? new EnvelopeSigner();
        if ($this->config->verifyEnvelopeSignature && $signer->isConfigured()) {
            $signature = isset($wire->_sig) && is_string($wire->_sig) ? $wire->_sig : null;
            if (! $signer->verify(EnvelopeFactory::canonicalJson($wire), $signature)) {
                $metrics?->increment('jobs_rejected_signature', 1, ['queue' => $queue]);
                log_message('critical', "QueueWorker: rejected message with invalid signature on queue '{$queue}'.");
                $this->backend->abandon($lease);

                return new WorkerResult('rejected', error: 'invalid signature');
            }
        }

        $attemptIndex = $lease->envelope->attempts; // completed runs before this one (0-based)
        $definition   = $this->definitionFromWire($wire);

        $context = new JobContext(
            payload: $wire->payload ?? null,
            name: isset($wire->name) && is_string($wire->name) ? $wire->name : null,
            queue: $queue,
            attempt: $attemptIndex + 1,
            meta: $lease->envelope->meta,
        );

        // Opt-in idempotency: skip if this key was already processed.
        $idempotencyKey = isset($wire->idempotencyKey) && is_string($wire->idempotencyKey) ? $wire->idempotencyKey : null;
        if ($idempotencyKey !== null) {
            $guard = $this->idempotency ?? new IdempotencyGuard();
            if (! $guard->firstRun($idempotencyKey)) {
                $metrics?->increment('jobs_skipped_idempotent', 1, ['queue' => $queue]);
                $this->backend->ack($lease);

                return new WorkerResult('skipped-idempotent', attempts: $attemptIndex);
            }
        }

        $runtime = $this->runtime ?? new JobRuntime();
        $result  = $runtime->run($definition, $context);

        if ($result->success) {
            $this->backend->ack($lease);
            $metrics?->increment('jobs_succeeded', 1, ['queue' => $queue]);

            return new WorkerResult('acked', attempts: $attemptIndex + 1);
        }

        $metrics?->increment('jobs_failed', 1, ['queue' => $queue]);

        $maxRetries = $definition->maxRetries ?? 0;
        if ($attemptIndex < $maxRetries) {
            $policy = $this->retryPolicy ?? $this->defaultRetryPolicy($this->config);
            $delay  = $policy->computeDelay($attemptIndex + 2);
            $this->backend->nack($lease, $delay);
            $metrics?->increment('jobs_requeued', 1, ['queue' => $queue]);

            return new WorkerResult('requeued', attempts: $attemptIndex + 1, error: $result->error);
        }

        // Retries exhausted: hand off to the backend's dead-letter path.
        $this->backend->abandon($lease);
        $metrics?->increment('jobs_failed_permanently', 1, ['queue' => $queue]);
        log_message('critical', "QueueWorker: job exhausted retries on queue '{$queue}': " . ($result->error ?? 'unknown error'));

        return new WorkerResult('dead-lettered', attempts: $attemptIndex + 1, error: $result->error);
    }

    private function definitionFromWire(stdClass $wire): JobDefinition
    {
        return new JobDefinition(
            handler: isset($wire->job) && is_string($wire->job) ? $wire->job : '',
            payload: $wire->payload ?? null,
            name: isset($wire->name) && is_string($wire->name) ? $wire->name : null,
            queue: isset($wire->queue) && is_string($wire->queue) ? $wire->queue : null,
            priority: isset($wire->priority) ? (int) $wire->priority : 5,
            maxRetries: isset($wire->maxRetries) ? (int) $wire->maxRetries : 0,
        );
    }

    private function defaultRetryPolicy(Jobs $config): RetryPolicy
    {
        return new RetryPolicyFixed(
            base: $config->retryBackoffBase,
            strategy: $config->retryBackoffStrategy,
            multiplier: $config->retryBackoffMultiplier,
            max: $config->retryBackoffMax,
            jitter: $config->retryBackoffJitter,
        );
    }
}
