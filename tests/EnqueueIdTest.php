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
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class EnqueueIdTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
    }

    private function makeJob(string $name = 'id_job', string $payload = 'x'): Job
    {
        $job = new Job(job: 'command', payload: $payload);
        $job->named($name);

        return $job;
    }

    public function testDatabaseQueueReturnsNonEmptyStringIds(): void
    {
        $config         = config('Jobs');
        $config->worker = 'database';
        $config->queues = 'default';
        $job            = $this->makeJob('db_queue_one', 'jobs:test');
        $job->setQueue('default');
        $id1 = $job->push();
        $this->assertIsString($id1);
        $this->assertNotSame('', $id1);

        $job2 = $this->makeJob('db_queue_two', 'jobs:test');
        $job2->setQueue('default');
        $id2 = $job2->push();
        $this->assertIsString($id2);
        $this->assertNotSame('', $id2);
        $this->assertNotSame($id1, $id2, 'IDs must be unique');
        $this->assertSame(32, strlen($id1), 'DatabaseQueue IDs expected length 32 alnum');
    }

    public function testSyncQueueReturnsSyntheticIds(): void
    {
        $config         = config('Jobs');
        $config->worker = 'sync';
        $config->queues = 'sync';
        $job            = $this->makeJob('sync_one', 'jobs:test');
        $job->setQueue('sync');
        $id1 = $job->push();
        $this->assertIsString($id1);
        $this->assertStringStartsWith('sync-', $id1);
        $this->assertGreaterThan(5, strlen($id1));

        $job2 = $this->makeJob('sync_two', 'jobs:test');
        $job2->setQueue('sync');
        $id2 = $job2->push();
        $this->assertNotSame($id1, $id2);
    }
}
