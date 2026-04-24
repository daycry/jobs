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

namespace Tests\Unit\Jobs;

use DateTime;
use Daycry\Jobs\Job;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Extended Job coverage — source, middleware, callbacks, fromQueueRecord.
 *
 * @internal
 */
final class JobExtendedTest extends TestCase
{
    public function testSourceGetterAndSetter(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $this->assertNull($job->getSource());
        $result = $job->source('cron');
        $this->assertSame($job, $result);
        $this->assertSame('cron', $job->getSource());
    }

    public function testMarkAsCallbackChild(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $this->assertFalse($job->isCallbackChild());
        $this->assertFalse($job->isCallbackChainAllowed());
        $job->markAsCallbackChild(true);
        $this->assertTrue($job->isCallbackChild());
        $this->assertTrue($job->isCallbackChainAllowed());
    }

    public function testMarkAsCallbackChildFalse(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $job->markAsCallbackChild(false);
        $this->assertTrue($job->isCallbackChild());
        $this->assertFalse($job->isCallbackChainAllowed());
    }

    public function testMiddlewareRegistration(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $this->assertSame([], $job->getMiddleware());

        $mw1 = static fn ($job, $next) => $next($job);
        $mw2 = static fn ($job, $next) => $next($job);

        $result = $job->middleware($mw1, $mw2);
        $this->assertSame($job, $result);
        $this->assertCount(2, $job->getMiddleware());
    }

    public function testToObject(): void
    {
        $job = new Job(job: 'command', payload: ['data' => 'test']);
        $obj = $job->toObject();
        $this->assertSame('command', $obj->job);
        $this->assertNull($obj->schedule);
    }

    public function testToObjectWithSchedule(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $job->scheduled(new DateTime('2024-01-01 12:00:00'));
        $obj = $job->toObject();
        $this->assertInstanceOf(DateTime::class, $obj->schedule);
    }

    public function testSetPayload(): void
    {
        $job = new Job(job: 'command', payload: 'original');
        $job->setPayload('modified');
        $this->assertSame('modified', $job->getPayload());
    }

    public function testFromQueueRecord(): void
    {
        $record             = new stdClass();
        $record->job        = 'command';
        $record->payload    = 'test-payload';
        $record->name       = 'MyJob';
        $record->queue      = 'default';
        $record->priority   = '5';
        $record->attempts   = '3';
        $record->maxRetries = '2';

        $job = Job::fromQueueRecord($record);
        $this->assertSame('command', $job->getJob());
        $this->assertSame('test-payload', $job->getPayload());
        $this->assertSame('MyJob', $job->getName());
        $this->assertSame('default', $job->getQueue());
        $this->assertSame(2, $job->getMaxRetries());
        $this->assertSame(3, $job->getAttempt());
    }

    public function testFromQueueRecordWithCallbackChild(): void
    {
        $record                       = new stdClass();
        $record->job                  = 'command';
        $record->payload              = 'x';
        $record->isCallbackChild      = true;
        $record->callbackChainAllowed = true;

        $job = Job::fromQueueRecord($record);
        $this->assertTrue($job->isCallbackChild());
        $this->assertTrue($job->isCallbackChainAllowed());
    }

    public function testFromQueueRecordWithSchedule(): void
    {
        $record           = new stdClass();
        $record->job      = 'command';
        $record->payload  = 'x';
        $record->schedule = '2024-06-15 10:00:00';

        $job = Job::fromQueueRecord($record);
        $this->assertSame('command', $job->getJob());
    }

    public function testFromQueueRecordWithInvalidScheduleLogsWarning(): void
    {
        $record           = new stdClass();
        $record->job      = 'command';
        $record->payload  = 'x';
        $record->schedule = 'not-a-valid-date-xyz!!!';

        // Should not throw; invalid schedule caught and logged
        $job = Job::fromQueueRecord($record);
        $this->assertSame('command', $job->getJob());
    }
}
