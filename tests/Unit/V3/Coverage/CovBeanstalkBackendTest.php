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

namespace Tests\Unit\V3\Coverage;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\Backends\BeanstalkBackend;
use Daycry\Jobs\Queues\JobLease;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\TubeName;
use stdClass;
use Throwable;

/**
 * Covers the not-yet-exercised branches of {@see BeanstalkBackend}: nack(delay>0), abandon (bury),
 * the corrupt-body bury path in fetch(), reapExpired==0, and the setPriority/setTtr fluent setters.
 *
 * Uses dedicated tubes and a thorough raw purge (ready + delayed + buried) so leftover jobs from a
 * previously aborted run never leak into these assertions.
 *
 * @internal
 */
final class CovBeanstalkBackendTest extends CIUnitTestCase
{
    private const QUEUES = ['covbs1', 'covbs2', 'covbs3', 'covbs4'];

    private BeanstalkBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backend = new BeanstalkBackend();
        if (! $this->backend->isConnected()) {
            $this->markTestSkipped('beanstalkd is not available on 127.0.0.1:11300');
        }
        $this->purge();
    }

    protected function tearDown(): void
    {
        if (isset($this->backend) && $this->backend->isConnected()) {
            $this->purge();
        }
        parent::tearDown();
    }

    private function rawConnection(): ?Pheanstalk
    {
        try {
            $conn = Pheanstalk::create('127.0.0.1', 11300);
            $conn->listTubes();

            return $conn;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Remove every ready, delayed and buried job from the test tubes via a raw connection so the
     * tubes start empty regardless of how a previous run terminated.
     */
    private function purge(): void
    {
        $conn = $this->rawConnection();
        if (! $conn instanceof Pheanstalk) {
            return;
        }

        foreach (self::QUEUES as $queue) {
            $tube = new TubeName($queue);
            $conn->useTube($tube);

            // Promote delayed + buried jobs to ready so a reserve loop can drain them.
            try {
                $conn->kick(1000);
            } catch (Throwable) {
                // nothing to kick
            }

            $conn->watch($tube);

            foreach ($conn->listTubesWatched() as $watched) {
                if ((string) $watched !== $queue) {
                    $conn->ignore($watched);
                }
            }

            for ($i = 0; $i < 200; $i++) {
                $job = $conn->reserveWithTimeout(0);
                if (! $job instanceof Job) {
                    break;
                }
                $conn->delete($job);
            }
        }
    }

    public function testNackWithDelayRequeuesWithIncrementedAttempts(): void
    {
        $this->backend->setTtr(120);
        $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'covbs1', maxRetries: 3));

        $lease = $this->backend->fetch('covbs1');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame(0, $lease->envelope->attempts);

        // delay > 0 path: delete + put with a delay and attempts+1.
        $this->assertTrue($this->backend->nack($lease, 1));

        // The job was re-put with a 1s delay; reserveWithTimeout (inside fetch) blocks until it
        // matures, so a single fetch with the backend's 5s reserve window reliably returns it.
        $found = $this->backend->fetch('covbs1');
        $this->assertInstanceOf(JobLease::class, $found);
        $this->assertSame(1, $found->envelope->attempts);
        $this->assertTrue($this->backend->ack($found));
    }

    public function testAbandonBuriesTheJob(): void
    {
        $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'covbs2'));

        $lease = $this->backend->fetch('covbs2');
        $this->assertInstanceOf(JobLease::class, $lease);

        // abandon => native bury; the job leaves the ready set and is not redelivered.
        $this->assertTrue($this->backend->abandon($lease));
        $this->assertNotInstanceOf(JobLease::class, $this->backend->fetch('covbs2'));
        // purge() in tearDown kicks + drains the buried job.
    }

    public function testFetchOfCorruptBodyBuriesItAndReturnsNull(): void
    {
        // Put a non-JSON body directly so fetch() hits the "corrupt body => bury" branch.
        $conn = $this->rawConnection();
        $this->assertInstanceOf(Pheanstalk::class, $conn);
        $conn->useTube(new TubeName('covbs3'));
        $conn->put('not-json{', 0, 0, 120);

        $lease = $this->backend->fetch('covbs3');
        $this->assertNotInstanceOf(JobLease::class, $lease);

        // The corrupt job was buried (not left ready): a second fetch still finds nothing.
        $this->assertNotInstanceOf(JobLease::class, $this->backend->fetch('covbs3'));
        // purge() in tearDown kicks + drains the buried corrupt job.
    }

    public function testReapExpiredIsAlwaysZero(): void
    {
        $this->assertSame(0, $this->backend->reapExpired('covbs4', 300));
    }

    public function testSetPriorityAndSetTtrAreFluent(): void
    {
        $this->assertInstanceOf(BeanstalkBackend::class, $this->backend->setPriority(7));
        $this->assertInstanceOf(BeanstalkBackend::class, $this->backend->setTtr(45));

        // setPriority feeds straight into enqueue/put; verify the round-trip still works.
        $id    = $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'covbs4'));
        $lease = $this->backend->fetch('covbs4');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame($id, $lease->envelope->id);

        $payload = $lease->envelope->payload;
        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertSame('command', $payload->job);

        $this->assertTrue($this->backend->ack($lease));
    }
}
