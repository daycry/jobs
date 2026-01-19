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
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RedisQueue;
use Daycry\Jobs\Queues\RequeueHelper;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class RedisQueueCycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueManager::reset();
    }

    public function testFullCycleWithRequeue(): void
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

        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
        // Ensure worker 'redis' points correctly
        $cfg         = config('Jobs');
        $cfg->worker = 'redis';
        $queueName   = 'redis_cycle_' . bin2hex(random_bytes(3));
        if (! str_contains((string) $cfg->queues, $queueName)) {
            $cfg->queues .= (empty($cfg->queues) ? '' : ',') . $queueName;
        }
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('redis_cycle')->setQueue($queueName)->maxRetries(2); // Add maxRetries to allow requeue
        $id1 = $job->push();
        $this->assertIsString($id1);

        $worker  = new RedisQueue();
        $fetched = $worker->watch($queueName);
        $this->assertNotNull($fetched, 'Should consume first job');
        $this->assertSame($id1, $fetched->id);

        // Simulate a failed execution cycle using RequeueHelper (authoritative attempts increment)
        $helper   = new RequeueHelper();
        $envelope = JobEnvelope::fromJob($job, []);
        $helper->finalize($job, $envelope, static function ($j, $recreate) use ($worker): void {
            $worker->removeJob($j, $recreate);
        }, false);
        $this->assertSame(1, $job->getAttempt(), 'Attempt incremented after failed cycle');

        // fetch requeued job
        $fetched2 = $worker->watch($queueName);
        $this->assertNotNull($fetched2, 'Should consume requeued job');
        $this->assertNotSame($id1, $fetched2->id, 'New identifier after requeue');
    }
}
