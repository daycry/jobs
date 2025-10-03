<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\RedisQueue;
use Tests\Support\TestCase;

/** @internal */
final class RedisQueueEnvelopeTest extends TestCase
{
    public function testDelayedJobEnvelopeContainsMetaAndAvailableAt(): void
    {
        if (! class_exists('Redis')) {
            $this->markTestSkipped('ext-redis not available');
        }
        try {
            $r = new Redis();
            if (! $r->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 0.5)) {
                $this->markTestSkipped('Redis not reachable');
            }
            $r->close();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis connect fail: ' . $e->getMessage());
        }

        $cfg = config('Jobs');
        $cfg->worker = 'redis';
        $queueName = 'redis_meta_' . bin2hex(random_bytes(3));
        if (! str_contains((string) $cfg->queues, $queueName)) {
            $cfg->queues .= (empty($cfg->queues) ? '' : ',') . $queueName;
        }

        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('redis_meta')->setQueue($queueName);
        $job->scheduled(new DateTime('+1 second'));
        $id = $job->push();
        $this->assertIsString($id);

        $worker = new RedisQueue();
        // First attempt (likely before promotion)
        $env = $worker->watch($queueName);
        if (! $env) {
            // Retry loop up to ~3s total
            for ($i = 0; $i < 8 && ! $env; $i++) {
                usleep(400_000);
                $env = $worker->watch($queueName);
            }
        }
        $this->assertNotNull($env, 'Delayed job should eventually be promoted');
        $this->assertArrayHasKey('delay', $env->meta);
        $this->assertGreaterThan(0, $env->meta['delay']);
        $this->assertNotNull($env->createdAt);
        $this->assertSame($queueName, $env->queue);
    }

    public function testWatchReturnsNullWhenNoJobs(): void
    {
        if (! class_exists('Redis')) {
            $this->markTestSkipped('ext-redis not available');
        }
        try {
            $r = new Redis();
            if (! $r->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 0.5)) {
                $this->markTestSkipped('Redis not reachable');
            }
            $r->close();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis connect fail: ' . $e->getMessage());
        }
        $worker = new RedisQueue();
        $this->assertNull($worker->watch('nonexistent_' . bin2hex(random_bytes(2))));
    }
}
