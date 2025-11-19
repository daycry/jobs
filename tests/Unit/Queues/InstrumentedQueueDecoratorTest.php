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

use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\InstrumentedQueueDecorator;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Queues\JobEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class InstrumentedQueueDecoratorTest extends TestCase
{
    private InMemoryMetricsCollector $metrics;
    private MockQueue $mockQueue;
    private InstrumentedQueueDecorator $instrumented;

    protected function setUp(): void
    {
        $this->metrics      = new InMemoryMetricsCollector();
        $this->mockQueue    = new MockQueue();
        $this->instrumented = new InstrumentedQueueDecorator(
            $this->mockQueue,
            $this->metrics,
            'mock',
        );
    }

    public function testEnqueueIncrementsSuccessMetric(): void
    {
        $data = (object) ['job' => 'test', 'queue' => 'default'];
        $this->mockQueue->setEnqueueResult('job-123');

        $id = $this->instrumented->enqueue($data);

        $this->assertSame('job-123', $id);
        $snapshot = $this->metrics->getSnapshot();
        $this->assertArrayHasKey('counters', $snapshot);
        $key = $this->buildMetricKey('queue_enqueue_total', ['backend' => 'mock', 'queue' => 'default', 'status' => 'success']);
        $this->assertSame(1, $snapshot['counters'][$key]);
    }

    public function testEnqueueIncrementsErrorMetricOnException(): void
    {
        $data = (object) ['job' => 'test', 'queue' => 'alpha'];
        $this->mockQueue->setEnqueueException(new RuntimeException('Connection failed'));

        try {
            $this->instrumented->enqueue($data);
            $this->fail('Expected exception not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Connection failed', $e->getMessage());
        }

        $snapshot = $this->metrics->getSnapshot();
        $key      = $this->buildMetricKey('queue_enqueue_total', ['backend' => 'mock', 'queue' => 'alpha', 'status' => 'error']);
        $this->assertSame(1, $snapshot['counters'][$key]);
    }

    public function testEnqueueRecordsDuration(): void
    {
        $data = (object) ['job' => 'test', 'queue' => 'default'];
        $this->mockQueue->setEnqueueResult('job-456');

        $this->instrumented->enqueue($data);

        $snapshot = $this->metrics->getSnapshot();
        $this->assertArrayHasKey('histograms', $snapshot);
        $key = $this->buildMetricKey('queue_enqueue_duration_seconds', ['backend' => 'mock', 'queue' => 'default']);
        $this->assertArrayHasKey($key, $snapshot['histograms']);
        $this->assertGreaterThan(0, $snapshot['histograms'][$key]['sum']);
    }

    public function testWatchIncrementsFetchTotalWhenEnvelopeReturned(): void
    {
        $envelope = new JobEnvelope(
            id: 'env-1',
            queue: 'beta',
            payload: (object) ['job' => 'test'],
            attempts: 0,
            createdAt: new DateTimeImmutable(),
        );
        $this->mockQueue->setWatchResult($envelope);

        $result = $this->instrumented->watch('beta');

        $this->assertSame($envelope, $result);
        $snapshot = $this->metrics->getSnapshot();
        $key      = $this->buildMetricKey('queue_fetch_total', ['backend' => 'mock', 'queue' => 'beta']);
        $this->assertSame(1, $snapshot['counters'][$key]);
    }

    public function testWatchIncrementsFetchEmptyWhenNullReturned(): void
    {
        $this->mockQueue->setWatchResult(null);

        $result = $this->instrumented->watch('gamma');

        $this->assertNull($result);
        $snapshot = $this->metrics->getSnapshot();
        $key      = $this->buildMetricKey('queue_fetch_empty_total', ['backend' => 'mock', 'queue' => 'gamma']);
        $this->assertSame(1, $snapshot['counters'][$key]);
    }

    public function testWatchRecordsDuration(): void
    {
        $this->mockQueue->setWatchResult(null);

        $this->instrumented->watch('delta');

        $snapshot = $this->metrics->getSnapshot();
        $key      = $this->buildMetricKey('queue_fetch_duration_seconds', ['backend' => 'mock', 'queue' => 'delta']);
        $this->assertArrayHasKey($key, $snapshot['histograms']);
        $this->assertGreaterThan(0, $snapshot['histograms'][$key]['sum']);
    }

    public function testRemoveJobIncrementsAckTotal(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $job->setQueue('epsilon');
        $this->mockQueue->setRemoveJobResult(true);

        $result = $this->instrumented->removeJob($job, false);

        $this->assertTrue($result);
        $snapshot = $this->metrics->getSnapshot();
        $key      = $this->buildMetricKey('queue_ack_total', ['backend' => 'mock', 'queue' => 'epsilon']);
        $this->assertSame(1, $snapshot['counters'][$key]);
    }

    public function testRemoveJobIncrementsNackTotalWhenRecreate(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $job->setQueue('zeta');
        $this->mockQueue->setRemoveJobResult(true);

        $result = $this->instrumented->removeJob($job, true);

        $this->assertTrue($result);
        $snapshot = $this->metrics->getSnapshot();
        $key      = $this->buildMetricKey('queue_nack_total', ['backend' => 'mock', 'queue' => 'zeta']);
        $this->assertSame(1, $snapshot['counters'][$key]);
    }

    public function testGetDecoratedReturnsOriginalQueue(): void
    {
        $decorated = $this->instrumented->getDecorated();
        $this->assertSame($this->mockQueue, $decorated);
    }

    private function buildMetricKey(string $name, array $labels): string
    {
        if ($labels === []) {
            return $name;
        }
        ksort($labels);

        return $name . '|' . http_build_query($labels, '', ';');
    }
}

/**
 * Simple mock queue for testing decorator.
 */
final class MockQueue implements QueueInterface, WorkerInterface
{
    private ?string $enqueueResult       = null;
    private ?Throwable $enqueueException = null;
    private mixed $watchResult           = null;
    private bool $removeJobResult        = true;

    public function setEnqueueResult(string $id): void
    {
        $this->enqueueResult = $id;
    }

    public function setEnqueueException(Throwable $e): void
    {
        $this->enqueueException = $e;
    }

    public function setWatchResult(mixed $result): void
    {
        $this->watchResult = $result;
    }

    public function setRemoveJobResult(bool $result): void
    {
        $this->removeJobResult = $result;
    }

    public function enqueue(object $data): string
    {
        if ($this->enqueueException) {
            throw $this->enqueueException;
        }

        return $this->enqueueResult ?? 'default-id';
    }

    public function watch(string $queue)
    {
        return $this->watchResult;
    }

    public function removeJob(Job $job, bool $recreate = false): bool
    {
        return $this->removeJobResult;
    }
}
