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

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\DatabaseQueue;
use Daycry\Jobs\Queues\JobEnvelope;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class DatabaseQueueWatchTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs          = config('Jobs');
        $jobs->queues  = 'default';
        $jobs->workers = ['database' => DatabaseQueue::class];
        $jobs->worker  = 'database';
    }

    public function testWatchReturnsJobEnvelope(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('db_watch')->enqueue('default');
        $id = $job->push();
        $this->assertIsString($id);

        $worker   = new DatabaseQueue();
        $envelope = $worker->watch('default');
        $this->assertInstanceOf(JobEnvelope::class, $envelope);
        $this->assertSame($id, $envelope->id);
        $this->assertSame('default', $envelope->queue);
        $this->assertIsObject($envelope->payload);
        $this->assertObjectHasProperty('job', $envelope->payload);
    }
}
