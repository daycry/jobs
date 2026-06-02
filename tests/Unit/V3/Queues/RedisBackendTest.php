<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Queues\Backends\RedisBackend;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\V2\JobDefinition;
use Redis;
use Throwable;

/**
 * @internal
 */
final class RedisBackendTest extends CIUnitTestCase
{
    private const QUEUES = ['v3rbq1', 'v3rbq2', 'v3rbq3'];
    private RedisBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backend = new RedisBackend();
        if (! $this->backend->isConnected()) {
            $this->markTestSkipped('Redis is not available on 127.0.0.1:6379');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->backend) && $this->backend->isConnected()) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function rawRedis(): ?Redis
    {
        try {
            $r = new Redis();
            $r->connect('127.0.0.1', 6379, 1.0);

            return $r;
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanup(): void
    {
        $r = $this->rawRedis();
        if (! $r instanceof Redis) {
            return;
        }

        foreach (self::QUEUES as $q) {
            foreach (['-waiting', '-delayed', '-processing', '-processing-meta'] as $s) {
                $r->del('jobs:' . $q . $s);
            }
        }
        $r->close();
    }

    public function testEnqueueFetchAckCycle(): void
    {
        $id    = $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'v3rbq1', maxRetries: 2));
        $lease = $this->backend->fetch('v3rbq1');

        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame($id, $lease->envelope->id);
        $this->assertSame('command', $lease->envelope->payload->job);

        $this->assertTrue($this->backend->ack($lease));
        $this->assertNull($this->backend->fetch('v3rbq1'));
    }

    public function testNackReserialisesIncrementedAttempts(): void
    {
        $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'v3rbq2', maxRetries: 3));

        $lease = $this->backend->fetch('v3rbq2');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame(0, $lease->envelope->attempts);

        $this->assertTrue($this->backend->nack($lease, 0));

        $lease2 = $this->backend->fetch('v3rbq2');
        $this->assertInstanceOf(JobLease::class, $lease2);
        // The legacy RedisQueue re-pushed the original raw, losing the increment; v3 must keep it.
        $this->assertSame(1, $lease2->envelope->attempts);
    }

    public function testReapExpiredRecoversStaleLease(): void
    {
        $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'v3rbq3'));

        $lease = $this->backend->fetch('v3rbq3');
        $this->assertInstanceOf(JobLease::class, $lease);

        // Backdate the lease timestamp so it looks abandoned (deterministic, no sleep).
        $r = $this->rawRedis();
        $this->assertInstanceOf(Redis::class, $r);
        $r->hSet('jobs:v3rbq3-processing-meta', $lease->token, json_encode(['ts' => 1, 'owner' => $lease->ownerToken]));
        $r->close();

        $reaped = $this->backend->reapExpired('v3rbq3', 300);
        $this->assertSame(1, $reaped);

        $this->assertInstanceOf(JobLease::class, $this->backend->fetch('v3rbq3'));
    }
}
