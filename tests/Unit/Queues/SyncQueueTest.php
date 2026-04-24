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

namespace Tests\Unit\Queues;

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\SyncQueue;
use stdClass;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class SyncQueueTest extends TestCase
{
    public function testWatchReturnsNull(): void
    {
        $q = new SyncQueue();
        $this->assertNull($q->watch('default'));
    }

    public function testRemoveJobReturnsTrue(): void
    {
        $q   = new SyncQueue();
        $job = new Job(job: 'command', payload: 'jobs:test');
        $this->assertTrue($q->removeJob($job));
        $this->assertTrue($q->removeJob($job, true));
    }

    public function testEnqueueReturnsIdentifier(): void
    {
        $q                = new SyncQueue();
        $data             = new stdClass();
        $data->job        = 'command';
        $data->payload    = 'jobs:test';
        $data->queue      = 'default';
        $data->priority   = 5;
        $data->attempts   = 0;
        $data->maxRetries = null;
        $identifier       = $q->enqueue($data);
        $this->assertNotEmpty($identifier);
        $this->assertStringContainsString('-', $identifier);
    }
}
