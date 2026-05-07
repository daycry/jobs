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

namespace Tests\Unit\Hotfixes;

use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\ConfigCache;
use Daycry\Jobs\Libraries\DeadLetterQueue;
use Tests\Support\TestCase;

/**
 * Hotfix F2/F4: DeadLetterQueue::store() must return bool to let callers decide
 * whether the origin can be safely cleared.
 *
 * @internal
 */
final class DeadLetterQueueReturnTest extends TestCase
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

    public function testStoreReturnsFalseWhenDlqDisabled(): void
    {
        $this->jobsConfig()->deadLetterQueue = null;
        ConfigCache::set($this->jobsConfig());

        $dlq = new DeadLetterQueue();
        $job = new Job(job: 'command', payload: 'test');

        $result = $dlq->store($job, 'unit test', 5);

        $this->assertFalse($result, 'store() must return false when DLQ is not configured.');
    }

    public function testStoreReturnsTrueWhenPushSucceeds(): void
    {
        $this->jobsConfig()->deadLetterQueue = 'default';
        ConfigCache::set($this->jobsConfig());

        // Use a closure that returns a non-throwing scalar so the SyncQueue lifecycle
        // succeeds and push() returns the queue identifier without raising.
        // The job MUST be ->named() because IdentityTrait::getName() falls back to
        // md5(serialize($payload)) which fails on Closure payloads.
        $dlq = new DeadLetterQueue();
        $job = new Job(job: 'closure', payload: static fn () => 'ok');
        $job->setQueue('default')->named('hotfix_dlq_return_ok');

        $result = $dlq->store($job, 'Max retries exceeded', 5);

        $this->assertTrue($result, 'store() must return true after a successful push to the DLQ.');
    }
}
