<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Queues;

use Config\Database;
use Daycry\Jobs\Models\QueueModel;
use Daycry\Jobs\Queues\Backends\DatabaseBackend;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\V2\JobDefinition;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class DatabaseBackendTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueModel::resetSkipLockedDetection();
    }

    public function testEnqueueFetchAckCycle(): void
    {
        $backend = new DatabaseBackend();
        $id      = $backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'dbq', maxRetries: 2));

        $lease = $backend->fetch('dbq');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame($id, $lease->envelope->id);
        $this->assertSame('command', $lease->envelope->payload->job);
        $this->assertSame(0, $lease->envelope->attempts);

        $this->assertTrue($backend->ack($lease));
        // Nothing left to fetch (row is completed).
        $this->assertNull($backend->fetch('dbq'));
    }

    public function testNackRequeuesSameRowWithIncrementedAttempts(): void
    {
        $backend = new DatabaseBackend();
        $id      = $backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'dbq2', maxRetries: 2));

        $lease = $backend->fetch('dbq2');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame(0, $lease->envelope->attempts);

        $this->assertTrue($backend->nack($lease, 0));

        $lease2 = $backend->fetch('dbq2');
        $this->assertInstanceOf(JobLease::class, $lease2);
        // Same logical job (same identifier) — no orphan row was created.
        $this->assertSame($id, $lease2->envelope->id);
        $this->assertSame(1, $lease2->envelope->attempts);

        // Exactly one row exists for this queue.
        $count = (new QueueModel())->where('queue', 'dbq2')->countAllResults();
        $this->assertSame(1, $count);
    }

    public function testReapExpiredRecoversStaleLease(): void
    {
        $backend = new DatabaseBackend();
        $backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'dbq3'));

        $lease = $backend->fetch('dbq3');
        $this->assertInstanceOf(JobLease::class, $lease);

        // Backdate the lease so it looks abandoned (bypass the model allowedFields via raw builder).
        $group = config('Jobs')->database['group'];
        $table = config('Jobs')->database['table'];
        Database::connect($group)->table($table)
            ->where('id', (int) $lease->token)
            ->update(['reserved_at' => '2000-01-01 00:00:00']);

        $reaped = $backend->reapExpired('dbq3', 300);
        $this->assertSame(1, $reaped);

        // Now fetchable again.
        $this->assertInstanceOf(JobLease::class, $backend->fetch('dbq3'));
    }
}
