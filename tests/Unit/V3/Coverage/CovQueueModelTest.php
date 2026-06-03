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

use Config\Database;
use Daycry\Jobs\Entities\Queue as QueueEntity;
use Daycry\Jobs\Models\QueueModel;
use ReflectionClass;
use Tests\Support\DatabaseTestCase;

/**
 * Line-coverage exercises for {@see QueueModel}: getJob(), reserveJob() (with/without owner
 * token), the optimistic-locking fallback, reapStuck(), requeueInPlace() and markStatus().
 *
 * @internal
 */
final class CovQueueModelTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueModel::resetSkipLockedDetection();
        $this->jobsConfig()->worker = 'database';
    }

    /**
     * Insert a pending row directly through the raw builder so we control every column,
     * including the v3 lease columns the model's allowedFields would otherwise reject.
     *
     * @param array<string, mixed> $overrides
     */
    private function insertRow(array $overrides = []): int
    {
        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        $now   = date('Y-m-d H:i:s');

        $data = array_merge([
            'identifier'  => bin2hex(random_bytes(6)),
            'queue'       => 'covq',
            'payload'     => json_encode(['job' => 'command', 'data' => 'jobs:test']),
            'priority'    => 5,
            'schedule'    => $now,
            'status'      => 'pending',
            'max_retries' => 3,
            'attempts'    => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $overrides);

        $db = Database::connect($group);
        $db->table($table)->insert($data);

        return (int) $db->insertID();
    }

    /**
     * @param array<string, mixed> $set
     */
    private function rawUpdate(int $id, array $set): void
    {
        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        Database::connect($group)->table($table)->where('id', $id)->update($set);
    }

    public function testGetJobReturnsHighestPriorityPendingRow(): void
    {
        // Lower priority number == sooner. Insert two rows; expect the priority-1 one.
        $this->insertRow(['queue' => 'gj', 'priority' => 8, 'identifier' => 'gj-low']);
        $this->insertRow(['queue' => 'gj', 'priority' => 1, 'identifier' => 'gj-high']);

        $job = (new QueueModel())->getJob();

        $this->assertInstanceOf(QueueEntity::class, $job);
        $this->assertSame('gj-high', $job->identifier);
        $this->assertSame('pending', $job->status);
    }

    public function testGetJobSkipsFutureSchedule(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->insertRow(['queue' => 'gjf', 'schedule' => $future, 'identifier' => 'gjf-future']);

        $job = (new QueueModel())->where('queue', 'gjf')->getJob();

        // The only matching row is scheduled in the future, so nothing is ready.
        if ($job instanceof QueueEntity) {
            $this->assertNotSame('gjf-future', $job->identifier);
        } else {
            $this->assertNull($job);
        }
    }

    public function testReserveJobWithOwnerTokenStampsLease(): void
    {
        $id = $this->insertRow(['queue' => 'rj', 'identifier' => 'rj-1']);

        $reserved = (new QueueModel())->reserveJob('rj', 'owner-token-abc');

        $this->assertInstanceOf(QueueEntity::class, $reserved);
        $this->assertSame($id, $reserved->id);
        $this->assertSame('in_progress', $reserved->status);

        // Verify the persisted lease columns via raw read.
        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        $row   = Database::connect($group)->table($table)->where('id', $id)->get()->getRow();
        $this->assertNotNull($row);
        $this->assertSame('owner-token-abc', $row->owner_token);
        $this->assertNotNull($row->reserved_at);
    }

    public function testReserveJobWithoutOwnerTokenStillReserves(): void
    {
        $id = $this->insertRow(['queue' => 'rjn', 'identifier' => 'rjn-1']);

        $reserved = (new QueueModel())->reserveJob('rjn');

        $this->assertInstanceOf(QueueEntity::class, $reserved);
        $this->assertSame($id, $reserved->id);
        $this->assertSame('in_progress', $reserved->status);
    }

    public function testReserveJobReturnsNullWhenQueueEmpty(): void
    {
        $reserved = (new QueueModel())->reserveJob('this-queue-has-no-rows');
        $this->assertNull($reserved);
    }

    public function testReserveJobOptimisticPathWhenSkipLockedDisabled(): void
    {
        // Force the optimistic-locking fallback by pinning supportsSkipLocked = false,
        // mirroring how the model behaves on SQLite/older databases.
        $ref  = new ReflectionClass(QueueModel::class);
        $prop = $ref->getProperty('supportsSkipLocked');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        try {
            $id = $this->insertRow(['queue' => 'opt', 'identifier' => 'opt-1']);

            $reserved = (new QueueModel())->reserveJob('opt', 'opt-owner');

            $this->assertInstanceOf(QueueEntity::class, $reserved);
            $this->assertSame($id, $reserved->id);
            $this->assertSame('in_progress', $reserved->status);

            // The optimistic path also returns null on an empty queue.
            $this->assertNull((new QueueModel())->reserveJob('opt-empty'));
        } finally {
            QueueModel::resetSkipLockedDetection();
        }
    }

    public function testReapStuckReturnsStaleLeaseToPending(): void
    {
        // Insert a row that looks abandoned: in_progress with an old reserved_at.
        $id = $this->insertRow([
            'queue'       => 'reap',
            'identifier'  => 'reap-1',
            'status'      => 'in_progress',
            'owner_token' => 'dead-worker',
        ]);
        $this->rawUpdate($id, ['reserved_at' => '2000-01-01 00:00:00']);

        $recovered = (new QueueModel())->reapStuck('reap', 300);
        $this->assertSame(1, $recovered);

        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        $row   = Database::connect($group)->table($table)->where('id', $id)->get()->getRow();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->owner_token);
        $this->assertNull($row->reserved_at);
    }

    public function testReapStuckIgnoresFreshLease(): void
    {
        // A recently reserved row must NOT be reaped.
        $id = $this->insertRow([
            'queue'      => 'reapfresh',
            'identifier' => 'reapfresh-1',
            'status'     => 'in_progress',
        ]);
        $this->rawUpdate($id, ['reserved_at' => date('Y-m-d H:i:s')]);

        $recovered = (new QueueModel())->reapStuck('reapfresh', 300);
        $this->assertSame(0, $recovered);
    }

    public function testRequeueInPlaceIncrementsAttemptsAndDelaysAvailability(): void
    {
        $id = $this->insertRow([
            'queue'      => 'rq',
            'identifier' => 'rq-1',
            'status'     => 'in_progress',
            'attempts'   => 2,
        ]);

        $ok = (new QueueModel())->requeueInPlace($id, 120);
        $this->assertTrue($ok);

        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        $row   = Database::connect($group)->table($table)->where('id', $id)->get()->getRow();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        $this->assertSame(3, (int) $row->attempts);
        $this->assertNull($row->owner_token);
        $this->assertNull($row->reserved_at);
        $this->assertNotNull($row->available_at);
        // available_at must be in the future relative to now.
        $this->assertGreaterThan(time(), strtotime((string) $row->available_at));
    }

    public function testRequeueInPlaceReturnsFalseForMissingRow(): void
    {
        $ok = (new QueueModel())->requeueInPlace(999999, 0);
        $this->assertFalse($ok);
    }

    public function testMarkStatusSetsTerminalStatus(): void
    {
        $id = $this->insertRow([
            'queue'       => 'mk',
            'identifier'  => 'mk-1',
            'status'      => 'in_progress',
            'owner_token' => 'worker-x',
        ]);

        $ok = (new QueueModel())->markStatus($id, 'completed');
        $this->assertTrue($ok);

        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        $row   = Database::connect($group)->table($table)->where('id', $id)->get()->getRow();
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertNull($row->owner_token);
        $this->assertNull($row->reserved_at);
    }

    public function testMarkStatusReturnsFalseForMissingRow(): void
    {
        $ok = (new QueueModel())->markStatus(999999, 'failed');
        $this->assertFalse($ok);
    }
}
