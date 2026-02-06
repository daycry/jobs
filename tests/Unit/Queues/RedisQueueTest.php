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
use Daycry\Jobs\Queues\RedisQueue;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class RedisQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
    }

    public function testEnqueueReturnsStringIdOrSkips(): void
    {
        if (! class_exists('Redis')) {
            $this->markTestSkipped('ext-redis not available');
        }

        // Quick connectivity check
        try {
            $r = new Redis();
            if (! $r->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 0.5)) {
                $this->markTestSkipped('Redis server not reachable');
            }
            $r->close();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }

        $queue = new RedisQueue();
        $job   = new Job(job: 'command', payload: 'jobs:test');
        $job->named('redis_enqueue')->setQueue('default');
        // For RedisQueue we pass object produced by toObject via push path normally; emulate minimal data
        $object        = $job->toObject();
        $object->queue = 'default';
        $id            = $queue->enqueue($object);
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        $this->assertMatchesRegularExpression('/^\d+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id, 'Expected timestamp-UUID format');
    }
}
