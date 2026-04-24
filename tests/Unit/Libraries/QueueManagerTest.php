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

use Daycry\Jobs\Exceptions\QueueException;
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\Queues\SyncQueue;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class QueueManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueManager::reset();
    }

    protected function tearDown(): void
    {
        QueueManager::reset();
        parent::tearDown();
    }

    public function testInstanceReturnsSingleton(): void
    {
        $a = QueueManager::instance();
        $b = QueueManager::instance();
        $this->assertSame($a, $b);
    }

    public function testResetClearsSingleton(): void
    {
        $a = QueueManager::instance();
        QueueManager::reset();
        $b = QueueManager::instance();
        $this->assertNotSame($a, $b);
    }

    public function testGetDefaultReturnsSyncQueue(): void
    {
        $manager = QueueManager::instance();
        $queue   = $manager->getDefault();
        $this->assertInstanceOf(SyncQueue::class, $queue);
    }

    public function testGetByNameReturnsSyncQueue(): void
    {
        $manager = QueueManager::instance();
        $queue   = $manager->get('sync');
        $this->assertInstanceOf(SyncQueue::class, $queue);
    }

    public function testGetSameNameReturnsCachedInstance(): void
    {
        $manager = QueueManager::instance();
        $a       = $manager->get('sync');
        $b       = $manager->get('sync');
        $this->assertSame($a, $b);
    }

    public function testGetInvalidWorkerThrows(): void
    {
        $this->expectException(QueueException::class);
        QueueManager::instance()->get('nonexistent');
    }

    public function testAvailableReturnsAllWorkerNames(): void
    {
        $manager   = QueueManager::instance();
        $available = $manager->available();
        $this->assertContains('sync', $available);
        $this->assertContains('database', $available);
    }

    public function testGetDefaultWorkerName(): void
    {
        $this->assertSame('sync', QueueManager::instance()->getDefaultWorkerName());
    }

    public function testRegisterAddsNewWorker(): void
    {
        $manager = QueueManager::instance();
        $manager->register('sync2', SyncQueue::class);
        $this->assertContains('sync2', $manager->available());
        $queue = $manager->get('sync2');
        $this->assertInstanceOf(SyncQueue::class, $queue);
    }

    public function testRegisterClearsCachedInstance(): void
    {
        $manager = QueueManager::instance();
        // Get the original instance
        $original = $manager->get('sync');
        // Re-register to clear cache
        $manager->register('sync', SyncQueue::class);
        $new = $manager->get('sync');
        // New instance should be created because cache was cleared
        $this->assertNotSame($original, $new);
    }
}
