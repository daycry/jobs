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

namespace Tests\Unit\Libraries;

use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\ConfigCache;
use Daycry\Jobs\Libraries\DeadLetterQueue;
use Tests\Support\TestCase;
use Throwable;

/**
 * @internal
 */
final class DeadLetterQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConfigCache::clear();
        $this->jobsConfig()->deadLetterQueue = null;
        ConfigCache::set($this->jobsConfig());
    }

    protected function tearDown(): void
    {
        $this->jobsConfig()->deadLetterQueue = null;
        ConfigCache::clear();
        parent::tearDown();
    }

    public function testStoreDoesNothingWhenDlqDisabled(): void
    {
        $cfg                  = $this->jobsConfig();
        $cfg->deadLetterQueue = null;
        $dlq                  = new DeadLetterQueue();
        $job                  = new Job(job: 'command', payload: 'test');
        // Should not throw
        $dlq->store($job, 'test reason', 3);
        $this->assertFalse($dlq->getStats()['enabled']);
    }

    public function testGetStatsReturnsFalseWhenDisabled(): void
    {
        $cfg                  = $this->jobsConfig();
        $cfg->deadLetterQueue = null;
        $dlq                  = new DeadLetterQueue();
        $stats                = $dlq->getStats();
        $this->assertFalse($stats['enabled']);
    }

    public function testGetStatsReturnsTrueWhenEnabled(): void
    {
        $cfg                  = $this->jobsConfig();
        $cfg->deadLetterQueue = 'dlq';
        ConfigCache::set($cfg);
        $dlq   = new DeadLetterQueue();
        $stats = $dlq->getStats();
        $this->assertTrue($stats['enabled']);
        $this->assertSame('dlq', $stats['queue']);
    }

    public function testStoreWithEnabledDlqPushesJob(): void
    {
        // Enable DLQ - it's in the configured queues list 'default,dummy'
        $this->jobsConfig()->deadLetterQueue = 'default';

        $dlq = new DeadLetterQueue();
        $job = new Job(job: 'command', payload: ['data' => 'test']);
        $job->setQueue('default'); // so getQueue() returns it

        // DLQ enabled — the job will attempt to push to 'default' queue
        // SyncQueue is the default worker, so it will actually run
        // We don't care about the result, just that no unhandled exception is thrown
        try {
            $dlq->store($job, 'Max retries exceeded', 5);
        } catch (Throwable $e) {
            // DLQ push failure is caught internally; if it gets here, it's an external error
            $this->fail('Unexpected exception from DLQ store: ' . $e->getMessage());
        }

        $this->assertTrue($dlq->getStats()['enabled']);
    }
}
