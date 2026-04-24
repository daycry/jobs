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

use Daycry\Jobs\Entities\Queue;
use Daycry\Jobs\Job;
use Daycry\Jobs\Models\QueueModel;
use Daycry\Jobs\Queues\DatabaseQueue;
use Tests\Support\DatabaseTestCase;

final readonly class _QueueModelFakeQuery
{
    public function __construct(private ?object $row)
    {
    }

    public function getRow(): ?object
    {
        return $this->row;
    }
}

final class _QueueModelFakeDbNoRow
{
    public int $transStartCalls    = 0;
    public int $transCompleteCalls = 0;

    public function prefixTable(string $table): string
    {
        return $table;
    }

    public function transStart(): void
    {
        $this->transStartCalls++;
    }

    public function transComplete(): void
    {
        $this->transCompleteCalls++;
    }

    public function query(): _QueueModelFakeQuery
    {
        return new _QueueModelFakeQuery(null);
    }
}

final class _QueueModelFakeDbOptimisticRace
{
    public int $attemptedUpdates = 0;

    public function prefixTable(string $table): string
    {
        return $table;
    }

    public function query(string $sql): _QueueModelFakeQuery|true
    {
        if (str_contains($sql, 'SELECT id')) {
            return new _QueueModelFakeQuery((object) ['id' => 123]);
        }

        $this->attemptedUpdates++;

        return true;
    }

    public function affectedRows(): int
    {
        // Simulate losing optimistic race every attempt
        return 0;
    }
}

/**
 * @internal
 */
final class QueueModelTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueModel::resetSkipLockedDetection();
    }

    protected function tearDown(): void
    {
        QueueModel::resetSkipLockedDetection();
        parent::tearDown();
    }

    private function enqueueTestJob(string $queue = 'default'): string
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->setQueue($queue);
        $dbQueue = new DatabaseQueue();

        return $dbQueue->enqueue($job->toObject());
    }

    public function testReserveJobReturnsQueueEntityForPendingJob(): void
    {
        $this->enqueueTestJob();
        $model = new QueueModel();

        $result = $model->reserveJob('default');
        $this->assertInstanceOf(Queue::class, $result);
        $this->assertSame('in_progress', $result->status);
    }

    public function testReserveJobReturnsNullWhenEmpty(): void
    {
        $model  = new QueueModel();
        $result = $model->reserveJob('nonexistent_queue_xyz');
        $this->assertNotInstanceOf(Queue::class, $result);
    }

    public function testGetJobReturnsFirstPendingJob(): void
    {
        $this->enqueueTestJob();
        $model  = new QueueModel();
        $result = $model->getJob();
        $this->assertInstanceOf(Queue::class, $result);
    }

    public function testResetSkipLockedDetection(): void
    {
        QueueModel::resetSkipLockedDetection();
        // After reset, should work normally
        $this->assertNull((new ReflectionClass(QueueModel::class))->getProperty('supportsSkipLocked')->getValue());
    }

    public function testReserveJobOptimisticFallback(): void
    {
        // Force optimistic fallback by simulating SKIP LOCKED not supported
        // We can access the static property via reflection
        $ref  = new ReflectionClass(QueueModel::class);
        $prop = $ref->getProperty('supportsSkipLocked');
        $prop->setValue(null, false); // Force false to use optimistic path

        $this->enqueueTestJob();
        $model  = new QueueModel();
        $result = $model->reserveJob('default');

        // Restore
        $prop->setValue(null, null);

        $this->assertInstanceOf(Queue::class, $result);
        $this->assertSame('in_progress', $result->status);
    }

    public function testReserveJobOptimisticFallbackReturnsNullWhenEmpty(): void
    {
        $ref  = new ReflectionClass(QueueModel::class);
        $prop = $ref->getProperty('supportsSkipLocked');
        $prop->setValue(null, false);

        $model  = new QueueModel();
        $result = $model->reserveJob('nonexistent_queue_xyz');

        $prop->setValue(null, null);

        $this->assertNotInstanceOf(Queue::class, $result);
    }

    public function testReserveJobSkipLockedReturnsNullWhenNoRowsWithoutFallingBack(): void
    {
        $model = new QueueModel();

        $dbRef  = new ReflectionClass($model);
        $dbProp = $dbRef->getProperty('db');
        $fakeDb = new _QueueModelFakeDbNoRow();
        $dbProp->setValue($model, $fakeDb);

        $supportsRef  = new ReflectionClass(QueueModel::class);
        $supportsProp = $supportsRef->getProperty('supportsSkipLocked');
        $supportsProp->setValue(null, null);

        $result = $model->reserveJob('default');

        $this->assertNotInstanceOf(Queue::class, $result);
        $this->assertSame(1, $fakeDb->transStartCalls);
        $this->assertSame(1, $fakeDb->transCompleteCalls);

        $supportsProp->setValue(null, null);
    }

    public function testReserveJobOptimisticReturnsNullAfterMaxAttempts(): void
    {
        $model = new QueueModel();

        $dbRef  = new ReflectionClass($model);
        $dbProp = $dbRef->getProperty('db');
        $fakeDb = new _QueueModelFakeDbOptimisticRace();
        $dbProp->setValue($model, $fakeDb);

        $supportsRef  = new ReflectionClass(QueueModel::class);
        $supportsProp = $supportsRef->getProperty('supportsSkipLocked');
        $supportsProp->setValue(null, false);

        $result = $model->reserveJob('default');

        $this->assertNotInstanceOf(Queue::class, $result);
        $this->assertSame(3, $fakeDb->attemptedUpdates);

        $supportsProp->setValue(null, null);
    }
}
