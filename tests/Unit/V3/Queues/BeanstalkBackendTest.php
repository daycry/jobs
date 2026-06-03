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

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\Backends\BeanstalkBackend;
use Daycry\Jobs\Queues\JobLease;
use stdClass;

/**
 * @internal
 */
final class BeanstalkBackendTest extends CIUnitTestCase
{
    private const QUEUES = ['v3bs1', 'v3bs2'];

    private BeanstalkBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backend = new BeanstalkBackend();
        if (! $this->backend->isConnected()) {
            $this->markTestSkipped('beanstalkd is not available on 127.0.0.1:11300');
        }
        $this->drain();
    }

    protected function tearDown(): void
    {
        if (isset($this->backend) && $this->backend->isConnected()) {
            $this->drain();
        }
        parent::tearDown();
    }

    /**
     * Reserve and delete everything left in the test tubes so leftover jobs from a failed
     * assertion never leak into the next run.
     */
    private function drain(): void
    {
        foreach (self::QUEUES as $queue) {
            for ($i = 0; $i < 50; $i++) {
                $lease = $this->backend->fetch($queue);
                if (! $lease instanceof JobLease) {
                    break;
                }
                $this->backend->ack($lease);
            }
        }
    }

    public function testEnqueueFetchAckCycle(): void
    {
        $id = $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'v3bs1', maxRetries: 2));

        $lease = $this->backend->fetch('v3bs1');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame($id, $lease->envelope->id);
        $this->assertSame('beanstalk', $lease->backend);

        $payload = $lease->envelope->payload;
        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertSame('command', $payload->job);

        $this->assertTrue($this->backend->ack($lease));
        $this->assertNull($this->backend->fetch('v3bs1'));
    }

    public function testNackReserialisesIncrementedAttempts(): void
    {
        $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'v3bs2', maxRetries: 3));

        $lease = $this->backend->fetch('v3bs2');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame(0, $lease->envelope->attempts);

        $this->assertTrue($this->backend->nack($lease, 0));

        $lease2 = $this->backend->fetch('v3bs2');
        $this->assertInstanceOf(JobLease::class, $lease2);
        // beanstalkd's native release cannot mutate the body; v3 deletes + re-puts with attempts+1.
        $this->assertSame(1, $lease2->envelope->attempts);

        $this->assertTrue($this->backend->ack($lease2));
    }

    public function testReapExpiredIsNoOp(): void
    {
        $this->assertSame(0, $this->backend->reapExpired('v3bs1', 300));
    }
}
