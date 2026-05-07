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

namespace Tests\Unit\V2;

use DateTimeImmutable;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as LegacyJob;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\V2\JobDefinition;
use Daycry\Jobs\V2\Queues\JobLease;
use Daycry\Jobs\V2\Queues\LegacyWorkerAdapter;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class LegacyWorkerAdapterTest extends TestCase
{
    /**
     * Returns an anonymous QueueInterface+WorkerInterface implementation that records its
     * inputs on public properties so the test body can assert on them. The PHPStan static
     * type cannot fully express "intersection plus extra writable properties" so we use
     * mixed and rely on the public property declarations for autocompletion.
     */
    private function makeWorker(): mixed
    {
        return new class () implements QueueInterface, WorkerInterface {
            public ?object $lastEnqueuedData  = null;
            public ?LegacyJob $lastRemovedJob = null;
            public ?bool $lastRecreate        = null;
            public ?JobEnvelope $nextWatch    = null;

            public function enqueue(object $data): string
            {
                $this->lastEnqueuedData = $data;

                return 'fake-id-' . uniqid('', true);
            }

            public function watch(string $queue): mixed
            {
                $env             = $this->nextWatch;
                $this->nextWatch = null;

                return $env;
            }

            public function removeJob(LegacyJob $job, bool $recreate = false): bool
            {
                $this->lastRemovedJob = $job;
                $this->lastRecreate   = $recreate;

                return true;
            }
        };
    }

    public function testEnqueuePassesDefinitionFieldsToWorker(): void
    {
        $worker  = $this->makeWorker();
        $adapter = new LegacyWorkerAdapter($worker, 'fake');

        $def = new JobDefinition(
            handler: 'command',
            payload: 'jobs:test',
            name: 'demo',
            queue: 'reports',
            priority: 7,
            maxRetries: 2,
        );

        $id = $adapter->enqueue($def);
        $this->assertNotEmpty($id);

        $this->assertNotNull($worker->lastEnqueuedData);
        $this->assertSame('command', $worker->lastEnqueuedData->job);
        $this->assertSame('jobs:test', $worker->lastEnqueuedData->payload);
        $this->assertSame('reports', $worker->lastEnqueuedData->queue);
        $this->assertSame(7, $worker->lastEnqueuedData->priority);
        $this->assertSame(2, $worker->lastEnqueuedData->maxRetries);
    }

    public function testFetchReturnsLeaseWhenEnvelopeAvailable(): void
    {
        $worker  = $this->makeVoidEnvelopeWorker($payload = (object) ['job' => 'command', 'payload' => 'p']);
        $adapter = new LegacyWorkerAdapter($worker, 'fake');

        $lease = $adapter->fetch('default');

        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame('fake', $lease->backend);
        $this->assertSame('id-1', $lease->token);
        $this->assertSame($payload, $lease->envelope->payload);
    }

    public function testFetchReturnsNullWhenWorkerEmpty(): void
    {
        $worker  = $this->makeWorker();
        $adapter = new LegacyWorkerAdapter($worker, 'fake');

        $this->assertNotInstanceOf(JobLease::class, $adapter->fetch('default'));
    }

    public function testAckCallsRemoveJobWithoutRecreate(): void
    {
        $worker  = $this->makeVoidEnvelopeWorker((object) ['job' => 'command', 'payload' => 'p']);
        $adapter = new LegacyWorkerAdapter($worker, 'fake');

        $lease = $adapter->fetch('default');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertTrue($adapter->ack($lease));
        $this->assertFalse($worker->lastRecreate);
    }

    public function testNackCallsRemoveJobWithRecreate(): void
    {
        $worker  = $this->makeVoidEnvelopeWorker((object) ['job' => 'command', 'payload' => 'p']);
        $adapter = new LegacyWorkerAdapter($worker, 'fake');

        $lease = $adapter->fetch('default');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertTrue($adapter->nack($lease));
        $this->assertTrue($worker->lastRecreate);
    }

    public function testFinaliseTwiceReturnsFalse(): void
    {
        $worker  = $this->makeVoidEnvelopeWorker((object) ['job' => 'command', 'payload' => 'p']);
        $adapter = new LegacyWorkerAdapter($worker, 'fake');

        $lease = $adapter->fetch('default');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertTrue($adapter->ack($lease));
        $this->assertFalse(
            $adapter->ack($lease),
            'A lease can only be finalised once; subsequent ack/nack must return false.',
        );
    }

    private function makeVoidEnvelopeWorker(object $payload): mixed
    {
        $worker            = $this->makeWorker();
        $worker->nextWatch = new JobEnvelope(
            id: 'id-1',
            queue: 'default',
            payload: $payload,
            attempts: 0,
            priority: null,
            scheduledAt: null,
            availableAt: null,
            createdAt: new DateTimeImmutable(),
            meta: [],
            raw: $payload,
        );

        return $worker;
    }
}
