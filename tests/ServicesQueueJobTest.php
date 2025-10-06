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

use Daycry\Jobs\Config\Services as JobsServices;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/** @internal */
final class ServicesQueueJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Inject stub worker
        $cfg                  = $this->jobsConfig();
        $cfg->queues          = 'alpha,beta';
        $cfg->workers['stub'] = StubQueue::class;
        $cfg->worker          = 'stub';
        StubQueue::$lastData  = null;
    }

    public function testQueueJobAppliesConfigureAndDefaults(): void
    {
        $id = JobsServices::queueJob(
            job: 'command',
            payload: 'jobs:test',
            queue: null, // should pick first: alpha
            configure: static function (Job $j): void {
                $j->named('on_demand')
                    ->priority(4)
                    ->singleInstance()
                    ->maxRetries(5)
                    ->timeout(120);
            },
        );

        $this->assertSame('stub-123', $id, 'Stub returned fixed identifier');
        $data = StubQueue::$lastData;
        $this->assertNotNull($data, 'Data captured in stub');
        $this->assertSame('command', $data->job);
        $this->assertSame('jobs:test', $data->payload);
        $this->assertSame('on_demand', $data->name);
        $this->assertSame('alpha', $data->queue, 'First queue chosen when null passed');
        $this->assertSame(4, $data->priority);
        $this->assertSame(5, $data->maxRetries);
        $this->assertSame(120, $data->timeout);
        $this->assertSame('queue', $data->source, 'Source auto-assigned to queue');
        $this->assertNull($data->schedule, 'No schedule when $when omitted');
    }

    public function testQueueJobWithWhenIntSchedules(): void
    {
        $start = time();
        JobsServices::queueJob('command', 'jobs:test', 'beta', null, 60);
        $data = StubQueue::$lastData;
        $this->assertNotNull($data);
        $this->assertNotNull($data->schedule, 'Schedule should be set');
        $diff = $data->schedule->getTimestamp() - $start;
        $this->assertTrue($diff >= 55 && $diff <= 65, 'Schedule timestamp approx 60s in future, got ' . $diff);
    }

    public function testQueueJobWithWhenString(): void
    {
        $future  = date('Y') + 1; // next year to avoid past date issues
        $dateStr = $future . '-01-01 00:00:00';
        JobsServices::queueJob('command', 'jobs:test', 'alpha', null, $dateStr);
        $data = StubQueue::$lastData;
        $this->assertNotNull($data);
        $this->assertSame($dateStr, $data->schedule->format('Y-m-d H:i:s'));
    }

    public function testQueueJobInvalidHandlerThrows(): void
    {
        $this->expectException(JobException::class);
        JobsServices::queueJob('invalid_handler');
    }

    public function testSyncQueueExecutesJobAndInlineCallbackImmediately(): void
    {
        $cfg         = $this->jobsConfig();
        $cfg->worker = 'sync'; // use real SyncQueue implementation

        $flag = false;

        $id = JobsServices::queueJob(
            job: 'command',
            payload: 'jobs:test',
            queue: 'alpha',
            configure: static function (Job $j) use (&$flag): void {
                $j->setCallbackJob(static function (Job $parent) use (&$flag) {
                    // Inline callback closure job (no queue) executed immediately
                    return new Job(job: 'closure', payload: static function () use (&$flag) {
                        $flag = true;

                        return 'done';
                    });
                }, [
                    'on'      => 'always',
                    'inherit' => ['name'],
                ]);
            },
        );

        $this->assertMatchesRegularExpression('/^sync-[0-9a-f]{12}$/', $id, 'SyncQueue ID format');
        $this->assertTrue($flag, 'Inline callback should have executed synchronously');
    }
}

/**
 * Simple stub queue capturing the last enqueued data object.
 */
final class StubQueue implements QueueInterface
{
    public static ?object $lastData = null;

    public function enqueue(object $data): string
    {
        self::$lastData = $data;

        return 'stub-123';
    }
}
