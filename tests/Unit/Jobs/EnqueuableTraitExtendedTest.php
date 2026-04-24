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

use CodeIgniter\I18n\Time;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\QueueManager;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class EnqueuableTraitExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueueManager::reset();

        $cfg                   = $this->jobsConfig();
        $cfg->queues           = 'default';
        $cfg->workers['stub2'] = StubQueue2::class;
        $cfg->worker           = 'stub2';
        StubQueue2::$lastData  = null;
    }

    protected function tearDown(): void
    {
        QueueManager::reset();
        parent::tearDown();
    }

    public function testScheduledWithTimeInstance(): void
    {
        $job      = new Job(job: 'command', payload: 'jobs:test');
        $timeObj  = Time::now()->addSeconds(60);
        $returned = $job->scheduled($timeObj);

        $this->assertSame($job, $returned);
        // Verify the schedule was set (can call toObject to check)
        $obj = $job->toObject();
        $this->assertNotNull($obj->schedule);
    }

    public function testPushClosureJobWithNonSyncQueue(): void
    {
        $job = new Job(job: 'closure', payload: static fn () => 'result');
        $job->setQueue('default');
        $job->push();

        // StubQueue2 captures the object; the closure is inside it
        $this->assertNotNull(StubQueue2::$lastData);
        $this->assertSame('closure', StubQueue2::$lastData->job);
    }

    public function testPushNonClosureJobWithNonSyncQueue(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->setQueue('default');
        $job->push();

        $this->assertNotNull(StubQueue2::$lastData);
        $this->assertSame('command', StubQueue2::$lastData->job);
    }
}

/**
 * Simple stub queue for EnqueuableTrait tests.
 */
final class StubQueue2 implements QueueInterface, WorkerInterface
{
    public static ?object $lastData = null;

    public function enqueue(object $data): string
    {
        self::$lastData = $data;

        return 'stub2-id';
    }

    public function watch(string $queue): mixed
    {
        return null;
    }

    public function removeJob(Job $job, bool $recreate = false): bool
    {
        return true;
    }
}
