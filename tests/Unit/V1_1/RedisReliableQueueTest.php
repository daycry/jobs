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

namespace Tests\Unit\V1_1;

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RedisQueue;
use ReflectionClass;
use Tests\Support\TestCase;
use Throwable;

/**
 * v1.1 reliable-queue tests for RedisQueue: items move to a processing list during
 * watch(), and reapStuckJobs() requeues items left there past the visibility timeout.
 *
 * Skipped automatically when no local Redis is available.
 *
 * @internal
 */
final class RedisReliableQueueTest extends TestCase
{
    private RedisQueue $queue;
    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Redis')) {
            $this->markTestSkipped('ext-redis not available');
        }

        $this->queue = new RedisQueue();
        $redis       = $this->reflectRedisClient();
        if ($redis === null) {
            $this->markTestSkipped('Redis server not reachable');
        }

        $this->queueName = 'v11_reliable_' . bin2hex(random_bytes(3));

        try {
            // Start each test from a clean slate.
            $redis->del($this->key('waiting'));
            $redis->del($this->key('processing'));
            $redis->del($this->key('processing-meta'));
            $redis->del($this->key('delayed'));
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $redis = $this->reflectRedisClient();
        if ($redis !== null) {
            try {
                $redis->del($this->key('waiting'));
                $redis->del($this->key('processing'));
                $redis->del($this->key('processing-meta'));
                $redis->del($this->key('delayed'));
            } catch (Throwable) {
                // ignore
            }
        }
        parent::tearDown();
    }

    private function reflectRedisClient(): ?object
    {
        $ref  = new ReflectionClass(RedisQueue::class);
        $prop = $ref->getProperty('redis');
        $val = $prop->getValue($this->queue);

        return is_object($val) ? $val : null;
    }

    private function key(string $suffix): string
    {
        return 'jobs:' . $this->queueName . '-' . $suffix;
    }

    public function testWatchMovesItemToProcessingListAndReturnsEnvelope(): void
    {
        $this->queue->enqueue((object) [
            'queue'   => $this->queueName,
            'payload' => 'work-1',
            'job'     => 'command',
        ]);

        $redis = $this->reflectRedisClient();
        $this->assertSame(1, $redis->lLen($this->key('waiting')));
        $this->assertSame(0, $redis->lLen($this->key('processing')));

        $env = $this->queue->watch($this->queueName);
        $this->assertInstanceOf(JobEnvelope::class, $env);

        $this->assertSame(0, $redis->lLen($this->key('waiting')), 'Waiting list must be drained');
        $this->assertSame(1, $redis->lLen($this->key('processing')), 'Item must move to processing');
        $this->assertSame(1, $redis->hLen($this->key('processing-meta')), 'Processing meta hash must record the lease timestamp');
    }

    public function testRemoveJobAcksClearProcessingList(): void
    {
        $this->queue->enqueue((object) [
            'queue'   => $this->queueName,
            'payload' => 'work-2',
            'job'     => 'command',
        ]);
        $env = $this->queue->watch($this->queueName);
        $this->assertInstanceOf(JobEnvelope::class, $env);

        $job = new Job(job: 'command', payload: 'work-2');
        $job->setQueue($this->queueName);
        $this->assertTrue($this->queue->removeJob($job, false));

        $redis = $this->reflectRedisClient();
        $this->assertSame(0, $redis->lLen($this->key('processing')));
        $this->assertSame(0, $redis->hLen($this->key('processing-meta')));
        $this->assertSame(0, $redis->lLen($this->key('waiting')));
    }

    public function testRemoveJobNackMovesItemBackToWaiting(): void
    {
        $this->queue->enqueue((object) [
            'queue'   => $this->queueName,
            'payload' => 'work-3',
            'job'     => 'command',
        ]);
        $env = $this->queue->watch($this->queueName);
        $this->assertInstanceOf(JobEnvelope::class, $env);

        $job = new Job(job: 'command', payload: 'work-3');
        $job->setQueue($this->queueName);
        $this->assertTrue($this->queue->removeJob($job, true));

        $redis = $this->reflectRedisClient();
        $this->assertSame(0, $redis->lLen($this->key('processing')));
        $this->assertSame(0, $redis->hLen($this->key('processing-meta')));
        $this->assertSame(1, $redis->lLen($this->key('waiting')), 'Nacked item must return to waiting list');
    }

    public function testReaperRequeuesStuckJobs(): void
    {
        $this->queue->enqueue((object) [
            'queue'   => $this->queueName,
            'payload' => 'work-4',
            'job'     => 'command',
        ]);
        $this->queue->watch($this->queueName);

        // Simulate a worker crash by overwriting the meta timestamp to an old value.
        $redis    = $this->reflectRedisClient();
        $rawItems = $redis->lRange($this->key('processing'), 0, -1);
        $this->assertCount(1, $rawItems);
        $redis->hSet($this->key('processing-meta'), $rawItems[0], (string) (time() - 10_000));

        $reaped = $this->queue->reapStuckJobs($this->queueName, 60);
        $this->assertSame(1, $reaped);
        $this->assertSame(1, $redis->lLen($this->key('waiting')));
        $this->assertSame(0, $redis->lLen($this->key('processing')));
        $this->assertSame(0, $redis->hLen($this->key('processing-meta')));
    }
}
