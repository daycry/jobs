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

use Daycry\Jobs\Libraries\ConfigCache;
use Daycry\Jobs\Libraries\DeadLetterQueue;
use Tests\Support\TestCase;

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

    public function testStoreReturnsFalseWhenDlqDisabled(): void
    {
        $cfg                  = $this->jobsConfig();
        $cfg->deadLetterQueue = null;
        ConfigCache::set($cfg);

        $dlq = new DeadLetterQueue();
        // No DLQ configured: store() must report failure rather than silently dropping.
        $this->assertFalse($dlq->store('test', 'command', 'test reason', 3));
        $this->assertFalse($dlq->getStats()['enabled']);
    }

    public function testGetStatsReturnsFalseWhenDisabled(): void
    {
        $cfg                  = $this->jobsConfig();
        $cfg->deadLetterQueue = null;
        ConfigCache::set($cfg);

        $stats = (new DeadLetterQueue())->getStats();
        $this->assertFalse($stats['enabled']);
    }

    public function testGetStatsReturnsTrueWhenEnabled(): void
    {
        $cfg                  = $this->jobsConfig();
        $cfg->deadLetterQueue = 'dlq';
        ConfigCache::set($cfg);

        $stats = (new DeadLetterQueue())->getStats();
        $this->assertTrue($stats['enabled']);
        $this->assertSame('dlq', $stats['queue']);
    }

    public function testStoreEnqueuesToDlqWhenEnabled(): void
    {
        $cfg                  = $this->jobsConfig();
        $cfg->deadLetterQueue = 'default';
        $cfg->worker          = 'sync'; // SyncBackend runs inline; just assert no exception escapes.
        ConfigCache::set($cfg);

        $dlq = new DeadLetterQueue();
        $this->assertTrue($dlq->store(['data' => 'test'], 'command', 'Max retries exceeded', 5));
    }
}
