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
use DateTimeImmutable;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\Backends\RedisBackend;
use Daycry\Jobs\Queues\JobLease;
use Redis;
use Throwable;

/**
 * Covers the not-yet-exercised branches of {@see RedisBackend}: delayed enqueue + promotion,
 * renewLease, reapExpired with a stale entry, abandon, and the corrupt-entry path in fetch().
 *
 * @internal
 */
final class CovRedisBackendTest extends CIUnitTestCase
{
    private const QUEUES = ['covrb1', 'covrb2', 'covrb3', 'covrb4'];

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

    public function testEnqueueWithFutureScheduleLandsInDelayedZsetUntilPromoted(): void
    {
        // scheduledAt in the future => delay > 0 => message goes to the delayed ZSET, not waiting.
        $this->backend->enqueue(new JobDefinition(
            handler: 'command',
            payload: 'jobs:test',
            queue: 'covrb1',
            scheduledAt: new DateTimeImmutable('+3600 seconds'),
        ));

        $r = $this->rawRedis();
        $this->assertInstanceOf(Redis::class, $r);
        $this->assertSame(1, $r->zCard('jobs:covrb1-delayed'));
        $this->assertSame(0, $r->lLen('jobs:covrb1-waiting'));

        // Not yet due: a fetch (which calls promoteDelayed) must not pull it.
        $this->assertNotInstanceOf(JobLease::class, $this->backend->fetch('covrb1'));

        // Backdate the score to the past so promoteDelayed moves it to waiting on the next fetch.
        $member = $r->zRange('jobs:covrb1-delayed', 0, -1);
        $this->assertIsArray($member);
        $this->assertArrayHasKey(0, $member);
        $raw = $member[0];
        $this->assertIsString($raw);
        $r->zAdd('jobs:covrb1-delayed', 1, $raw);
        $r->close();

        $lease = $this->backend->fetch('covrb1');
        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertTrue($this->backend->ack($lease));
    }

    public function testRenewLeaseReStampsProcessingMetaTimestamp(): void
    {
        $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'covrb2'));

        $lease = $this->backend->fetch('covrb2');
        $this->assertInstanceOf(JobLease::class, $lease);

        // Backdate the meta timestamp to make the lease look stale.
        $r = $this->rawRedis();
        $this->assertInstanceOf(Redis::class, $r);
        $r->hSet('jobs:covrb2-processing-meta', $lease->token, (string) json_encode(['ts' => 1, 'owner' => $lease->ownerToken]));

        $this->assertTrue($this->backend->renewLease($lease));

        // After renew, the stamped timestamp is "now" again, so the reaper must NOT reclaim it.
        $reaped = $this->backend->reapExpired('covrb2', 300);
        $this->assertSame(0, $reaped);

        $metaJson = $r->hGet('jobs:covrb2-processing-meta', $lease->token);
        $this->assertIsString($metaJson);
        $meta = json_decode($metaJson, true);
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('ts', $meta);
        $this->assertGreaterThan(1, (int) $meta['ts']);
        $r->close();

        $this->assertTrue($this->backend->ack($lease));
    }

    public function testAbandonDropsFromProcessingWithoutRequeue(): void
    {
        $this->backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'covrb3'));

        $lease = $this->backend->fetch('covrb3');
        $this->assertInstanceOf(JobLease::class, $lease);

        $this->assertTrue($this->backend->abandon($lease));

        $r = $this->rawRedis();
        $this->assertInstanceOf(Redis::class, $r);
        // abandon does NOT requeue: nothing in waiting/processing afterwards.
        $this->assertSame(0, $r->lLen('jobs:covrb3-waiting'));
        $this->assertSame(0, $r->lLen('jobs:covrb3-processing'));
        $this->assertSame(0, $r->hLen('jobs:covrb3-processing-meta'));
        $r->close();

        $this->assertNotInstanceOf(JobLease::class, $this->backend->fetch('covrb3'));
    }

    public function testFetchOfCorruptEntryReturnsNullAndCleansProcessing(): void
    {
        // Push a non-JSON payload straight onto the waiting list: fetch() must drop it and return null.
        $r = $this->rawRedis();
        $this->assertInstanceOf(Redis::class, $r);
        $r->lPush('jobs:covrb4-waiting', 'not-valid-json{');
        $r->close();

        $lease = $this->backend->fetch('covrb4');
        $this->assertNotInstanceOf(JobLease::class, $lease);

        $r2 = $this->rawRedis();
        $this->assertInstanceOf(Redis::class, $r2);
        // The corrupt entry is removed from processing and its meta cleaned up.
        $this->assertSame(0, $r2->lLen('jobs:covrb4-processing'));
        $this->assertSame(0, $r2->hLen('jobs:covrb4-processing-meta'));
        $r2->close();
    }
}
