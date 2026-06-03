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

namespace Tests\Unit\V3\Coverage;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Metrics\MetricsCollectorInterface;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;
use Daycry\Jobs\Worker\QueueWorker;
use RuntimeException;
use stdClass;

/**
 * In-memory QueueBackend double recording the terminal operation (unique to this Coverage suite).
 *
 * @internal
 */
final class CovWorkerBackend implements QueueBackend
{
    public ?string $terminal = null;
    public ?int $nackDelay   = null;

    public function __construct(private ?JobLease $next)
    {
    }

    public function enqueue(JobDefinition $definition): string
    {
        return 'cov-id';
    }

    public function fetch(string $queue): ?JobLease
    {
        $lease      = $this->next;
        $this->next = null;

        return $lease;
    }

    public function ack(JobLease $lease): bool
    {
        $this->terminal = 'ack';

        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        $this->terminal  = 'nack';
        $this->nackDelay = $delaySeconds;

        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        $this->terminal = 'abandon';

        return true;
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        return 0;
    }
}

/**
 * Recording metrics collector so the QueueWorker's $metrics?->increment(...) branches run with a
 * non-null collector (the existing suite relies on the default Metrics singleton).
 *
 * @internal
 */
final class CovRecordingMetrics implements MetricsCollectorInterface
{
    /**
     * @var list<string>
     */
    public array $counters = [];

    public function increment(string $counter, int $value = 1, array $labels = []): void
    {
        $this->counters[] = $counter;
    }

    public function observe(string $metric, float $value, array $labels = []): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return [];
    }
}

/**
 * Covers the QueueWorker branches not exercised by the main suite: the non-stdClass payload
 * rejection (=> abandon) and the metrics-collector counter paths for fetched/succeeded/failed/
 * requeued/permanently-failed.
 *
 * @internal
 */
final class CovQueueWorkerTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $fields
     */
    private function lease(array $fields, int $attempts): JobLease
    {
        $wire = new stdClass();

        foreach ($fields as $k => $v) {
            $wire->{$k} = $v;
        }

        $envelope = new JobEnvelope(id: 'id', queue: 'q', payload: $wire, attempts: $attempts, meta: []);

        return new JobLease($envelope, 'tok', 'owner', new DateTimeImmutable('+300 seconds'), 'cov');
    }

    private function unsigned(CovWorkerBackend $backend, CovRecordingMetrics $metrics): QueueWorker
    {
        // Unconfigured signer ('') => signature verification is skipped deterministically.
        return new QueueWorker($backend, signer: new EnvelopeSigner(''), metrics: $metrics);
    }

    public function testNonStdClassPayloadIsRejectedAndAbandoned(): void
    {
        // payload is an array (not stdClass) => the worker rejects + abandons before any run.
        $envelope = new JobEnvelope(id: 'id', queue: 'q', payload: ['not' => 'stdclass'], attempts: 0, meta: []);
        $lease    = new JobLease($envelope, 'tok', 'owner', new DateTimeImmutable('+300 seconds'), 'cov');

        $backend = new CovWorkerBackend($lease);
        $metrics = new CovRecordingMetrics();
        $result  = $this->unsigned($backend, $metrics)->processOnce('q');

        $this->assertSame('rejected', $result->status);
        $this->assertSame('invalid payload', $result->error);
        $this->assertSame('abandon', $backend->terminal);
        // Fetch was counted; nothing succeeded.
        $this->assertContains('jobs_fetched', $metrics->counters);
    }

    public function testSuccessIncrementsFetchedAndSucceededCounters(): void
    {
        $backend = new CovWorkerBackend($this->lease([
            'job'        => 'closure',
            'payload'    => static fn (): string => 'ok',
            'queue'      => 'q',
            'maxRetries' => 0,
        ], 0));
        $metrics = new CovRecordingMetrics();

        $result = $this->unsigned($backend, $metrics)->processOnce('q');

        $this->assertSame('acked', $result->status);
        $this->assertSame('ack', $backend->terminal);
        $this->assertContains('jobs_fetched', $metrics->counters);
        $this->assertContains('jobs_succeeded', $metrics->counters);
    }

    public function testRequeueIncrementsFailedAndRequeuedCounters(): void
    {
        $backend = new CovWorkerBackend($this->lease([
            'job'        => 'closure',
            'payload'    => static function (): void { throw new RuntimeException('boom'); },
            'queue'      => 'q',
            'maxRetries' => 2,
        ], 0));
        $metrics = new CovRecordingMetrics();

        $result = $this->unsigned($backend, $metrics)->processOnce('q');

        $this->assertSame('requeued', $result->status);
        $this->assertSame('nack', $backend->terminal);
        $this->assertContains('jobs_failed', $metrics->counters);
        $this->assertContains('jobs_requeued', $metrics->counters);
    }

    public function testExhaustedRetriesIncrementsPermanentFailureCounter(): void
    {
        // attempts (2) == maxRetries (2) => no retry left => abandon + permanent failure metric.
        $backend = new CovWorkerBackend($this->lease([
            'job'        => 'closure',
            'payload'    => static function (): void { throw new RuntimeException('boom'); },
            'queue'      => 'q',
            'maxRetries' => 2,
        ], 2));
        $metrics = new CovRecordingMetrics();

        $result = $this->unsigned($backend, $metrics)->processOnce('q');

        $this->assertSame('dead-lettered', $result->status);
        $this->assertSame('abandon', $backend->terminal);
        $this->assertContains('jobs_failed_permanently', $metrics->counters);
    }
}
