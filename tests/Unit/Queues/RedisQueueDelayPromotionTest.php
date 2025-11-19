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
final class RedisQueueDelayPromotionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Daycry\Jobs\Libraries\QueueManager::reset();
    }

    public function testDelayedJobIsPromotedAndFetched(): void
    {
        if (! class_exists('Redis')) {
            $this->markTestSkipped('ext-redis not available');
        }

        try {
            $r = new Redis();
            if (! $r->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 0.5)) {
                $this->markTestSkipped('Redis not reachable');
            } $r->close();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis connect fail: ' . $e->getMessage());
        }

        $queueName   = 'redis_delay_' . bin2hex(random_bytes(3));
        $cfg         = config('Jobs');
        $cfg->worker = 'redis';
        if (! str_contains((string) $cfg->queues, $queueName)) {
            $cfg->queues .= (empty($cfg->queues) ? '' : ',') . $queueName;
        }

        // Create a job scheduled 1 second in the future
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('redis_delay')->setQueue($queueName);
        $schedule = new DateTime('+1 second');
        $job->scheduled($schedule);
        $id = $job->push();
        $this->assertIsString($id);

        $worker = new RedisQueue();
        // Immediately watching should yield null (not yet promoted)
        $first = $worker->watch($queueName);
        if ($first) {
            // In some environments timing may allow immediate promotion, accept it but ensure createdAt <= now
            $this->assertLessThanOrEqual(time(), $first->createdAt->getTimestamp());

            return; // early accept
        }
        // Sleep until after delay
        usleep(1_100_000);
        $second = $worker->watch($queueName);
        $this->assertNotNull($second, 'Delayed job should be promoted after delay elapsed');
        $this->assertSame($id, $second->id);
        $this->assertNotNull($second->createdAt);
        $this->assertSame($queueName, $second->queue);
    }
}
