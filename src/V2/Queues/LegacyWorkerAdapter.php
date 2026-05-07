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

namespace Daycry\Jobs\V2\Queues;

use DateTime;
use DateTimeImmutable;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as LegacyJob;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\V2\JobDefinition;

/**
 * Bridges the v1 split (QueueInterface + WorkerInterface) to the v2 QueueBackend
 * contract so existing backends can be used from the new lease-oriented API
 * without a full rewrite.
 *
 * Trade-offs of using the adapter (vs. a native v2 backend):
 *  - The adapter relies on the legacy backend's per-instance state (ServiceBusQueue's
 *    $this->job, RedisQueue's $this->lastRaw, etc.). Each LegacyWorkerAdapter instance
 *    can therefore safely carry only ONE in-flight lease at a time — concurrent
 *    fetch() calls on the same adapter are NOT supported. Wrap one adapter per worker.
 *  - The lease token is opaque and only meaningful for the bound legacy backend.
 *  - Backends without native DLQ semantics treat abandon() as ack() (drop without retry).
 */
final class LegacyWorkerAdapter implements QueueBackend
{
    /**
     * Tracks the last leased envelope so ack/nack can re-issue removeJob() with the
     * appropriate $job argument. The legacy WorkerInterface::removeJob() expects the
     * Job that was originally enqueued; we reconstruct it from the envelope.
     */
    private ?JobEnvelope $currentEnvelope = null;

    public function __construct(
        private readonly QueueInterface&WorkerInterface $worker,
        private readonly string $backendName,
        private readonly int $defaultLeaseSeconds = 300,
    ) {
    }

    public function enqueue(JobDefinition $definition): string
    {
        $payload = (object) [
            'job'        => $definition->handler,
            'payload'    => $definition->payload,
            'queue'      => $definition->queue ?? 'default',
            'priority'   => $definition->priority,
            'maxRetries' => $definition->maxRetries,
            'attempts'   => 0,
            'name'       => $definition->name,
            'schedule'   => $definition->scheduledAt instanceof DateTimeImmutable
                ? DateTime::createFromInterface($definition->scheduledAt)
                : null,
        ];

        return $this->worker->enqueue($payload);
    }

    public function fetch(string $queue): ?JobLease
    {
        $envelope = $this->worker->watch($queue);

        if (! $envelope instanceof JobEnvelope) {
            return null;
        }

        $this->currentEnvelope = $envelope;

        // Generate a lease token. The legacy worker tracks state internally so the token
        // does not need to be opaque to the backend; we reuse the envelope id which is
        // unique per lease and convenient for log correlation.
        return JobLease::withRelativeExpiry(
            envelope: $envelope,
            token: $envelope->id,
            secondsFromNow: $this->defaultLeaseSeconds,
            backend: $this->backendName,
        );
    }

    public function ack(JobLease $lease): bool
    {
        return $this->finalize($lease, recreate: false);
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        // Legacy backends do not accept a delay on requeue; we honour the contract by
        // dropping the value silently. Native v2 backends added later can override.
        unset($delaySeconds);

        return $this->finalize($lease, recreate: true);
    }

    public function abandon(JobLease $lease): bool
    {
        // Legacy WorkerInterface has no native abandon; treat as ack so the message is
        // dropped from the origin queue. Operators relying on broker-level DLQ should
        // configure the worker's own DLQ feature ($cfg->deadLetterQueue).
        return $this->finalize($lease, recreate: false);
    }

    /**
     * Translate a v2 lease op into the legacy removeJob(Job, recreate) call.
     */
    private function finalize(JobLease $lease, bool $recreate): bool
    {
        if (! $this->currentEnvelope instanceof JobEnvelope || $this->currentEnvelope->id !== $lease->token) {
            // The lease was not issued by this adapter (or it has already been finalised).
            return false;
        }

        $stub = LegacyJob::fromQueueRecord((object) [
            'job'        => $this->envelopePayload($lease->envelope, 'job'),
            'payload'    => $this->envelopePayload($lease->envelope, 'payload'),
            'queue'      => $lease->envelope->queue,
            'name'       => $this->envelopePayload($lease->envelope, 'name'),
            'priority'   => $this->envelopePayload($lease->envelope, 'priority'),
            'maxRetries' => $this->envelopePayload($lease->envelope, 'maxRetries'),
            'attempts'   => $lease->envelope->attempts,
        ]);

        $ok = $this->worker->removeJob($stub, $recreate);

        $this->currentEnvelope = null;

        return $ok;
    }

    private function envelopePayload(JobEnvelope $env, string $key): mixed
    {
        $payload = $env->payload;
        if (is_array($payload) && array_key_exists($key, $payload)) {
            return $payload[$key];
        }
        if (is_object($payload) && property_exists($payload, $key)) {
            /** @phpstan-ignore property.dynamicName (envelope payload is intentionally generic) */
            return $payload->{$key};
        }

        return null;
    }
}
