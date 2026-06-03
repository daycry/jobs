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
use Daycry\Jobs\Queues\Backends\SyncBackend;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;
use stdClass;

/**
 * Covers {@see SyncBackend}: inline execution at enqueue() (returning a synthetic id), the always
 * empty fetch(), and the trivial lease verbs (ack/nack/abandon return true, reapExpired returns 0).
 *
 * @internal
 */
final class CovSyncBackendTest extends CIUnitTestCase
{
    private function lease(): JobLease
    {
        $wire      = new stdClass();
        $wire->job = 'command';
        $envelope  = new JobEnvelope(id: 'id', queue: 'q', payload: $wire, attempts: 0, meta: []);

        return new JobLease($envelope, 'tok', 'owner', new DateTimeImmutable('+300 seconds'), 'sync');
    }

    public function testEnqueueRunsInlineAndReturnsSyntheticId(): void
    {
        $ran     = false;
        $backend = new SyncBackend();

        $id = $backend->enqueue(new JobDefinition(
            handler: 'closure',
            payload: static function () use (&$ran): string {
                $ran = true;

                return 'ok';
            },
            queue: 'q',
        ));

        $this->assertTrue($ran, 'SyncBackend must execute the job inline at enqueue() time.');
        $this->assertStringStartsWith('sync-', $id);
    }

    public function testEnqueueWithNullQueueDefaultsToDefault(): void
    {
        $backend = new SyncBackend();

        // queue: null exercises the "?? 'default'" branch in enqueue().
        $id = $backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test'));

        $this->assertStringStartsWith('sync-', $id);
    }

    public function testFetchAlwaysReturnsNull(): void
    {
        $this->assertNotInstanceOf(JobLease::class, (new SyncBackend())->fetch('q'));
    }

    public function testLeaseVerbsAreTrivialNoOps(): void
    {
        $backend = new SyncBackend();
        $lease   = $this->lease();

        $this->assertTrue($backend->ack($lease));
        $this->assertTrue($backend->nack($lease));
        $this->assertTrue($backend->nack($lease, 30));
        $this->assertTrue($backend->abandon($lease));
        $this->assertSame(0, $backend->reapExpired('q', 300));
    }
}
