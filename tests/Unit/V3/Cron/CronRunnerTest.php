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

namespace Tests\Unit\V3\Cron;

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Cron\CronRunner;
use Daycry\Jobs\Cron\Scheduler;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;

/**
 * @internal
 */
final class CronRunnerTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingBackend::$enqueued = [];
    }

    private function configWithRecordingBackend(): Jobs
    {
        $config                        = config(Jobs::class);
        $config->worker                = 'recording';
        $config->backends['recording'] = RecordingBackend::class;

        return $config;
    }

    public function testDueInlineJobRunsThroughRuntime(): void
    {
        $executed  = false;
        $scheduler = new Scheduler();
        $scheduler->define('closure', static function () use (&$executed) {
            $executed = true;

            return 'ok';
        })->named('inline')->everyMinute();

        $runner = new CronRunner($scheduler, $this->configWithRecordingBackend());
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertTrue($executed, 'Inline job closure should have executed.');
        $this->assertSame([], RecordingBackend::$enqueued, 'Inline job must not be enqueued.');
    }

    public function testDueQueuedJobIsEnqueuedNotRunInline(): void
    {
        $executed  = false;
        $scheduler = new Scheduler();
        $scheduler->define('closure', static function () use (&$executed) {
            $executed = true;

            return 'ok';
        })->named('queued')->everyMinute()->queue('reports');

        $runner = new CronRunner($scheduler, $this->configWithRecordingBackend());
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertFalse($executed, 'Queued job must not run inline.');
        $this->assertCount(1, RecordingBackend::$enqueued);
        $this->assertSame('queued', RecordingBackend::$enqueued[0]->name);
        $this->assertSame('reports', RecordingBackend::$enqueued[0]->queue);
    }

    public function testDisabledJobIsSkipped(): void
    {
        $executed  = false;
        $scheduler = new Scheduler();
        $scheduler->define('closure', static function () use (&$executed): void {
            $executed = true;
        })->named('off')->everyMinute()->disable();

        $runner = new CronRunner($scheduler, $this->configWithRecordingBackend());
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertFalse($executed);
        $this->assertSame([], RecordingBackend::$enqueued);
    }

    public function testNotDueJobIsSkipped(): void
    {
        $executed  = false;
        $scheduler = new Scheduler();
        // Runs daily at 02:00; evaluating at 10:00 is not due.
        $scheduler->define('closure', static function () use (&$executed): void {
            $executed = true;
        })->named('nightly')->dailyAt('02:00');

        $runner = new CronRunner($scheduler, $this->configWithRecordingBackend());
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertFalse($executed);
    }

    public function testDueJobAtScheduledTimeRunsInline(): void
    {
        $executed  = false;
        $scheduler = new Scheduler();
        $scheduler->define('closure', static function () use (&$executed): void {
            $executed = true;
        })->named('nightly')->dailyAt('02:00');

        $runner = new CronRunner($scheduler, $this->configWithRecordingBackend());
        $runner->run(Time::parse('2026-06-01 02:00:00'));

        $this->assertTrue($executed);
    }

    public function testEnvironmentRestrictionSkipsForeignEnvironment(): void
    {
        $executed  = false;
        $scheduler = new Scheduler();
        $scheduler->define('closure', static function () use (&$executed): void {
            $executed = true;
        })->named('prod-only')->everyMinute()->environments('production');

        $runner = new CronRunner($scheduler, $this->configWithRecordingBackend());
        // ENVIRONMENT is 'testing' during the suite, so a 'production'-only job must be skipped.
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertFalse($executed);
    }

    public function testExecutionFollowsDependencyOrder(): void
    {
        $order     = [];
        $scheduler = new Scheduler();
        $scheduler->define('closure', static function () use (&$order): void {
            $order[] = 'a';
        })->named('a')->everyMinute()->dependsOn('b');
        $scheduler->define('closure', static function () use (&$order): void {
            $order[] = 'b';
        })->named('b')->everyMinute();

        $runner = new CronRunner($scheduler, config('Jobs'));
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertSame(['b', 'a'], $order);
    }
}

/**
 * Records every definition handed to enqueue() without performing real work.
 */
final class RecordingBackend implements QueueBackend
{
    /**
     * @var list<JobDefinition>
     */
    public static array $enqueued = [];

    public function enqueue(JobDefinition $definition): string
    {
        self::$enqueued[] = $definition;

        return 'rec-' . count(self::$enqueued);
    }

    public function fetch(string $queue): ?JobLease
    {
        return null;
    }

    public function ack(JobLease $lease): bool
    {
        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        return true;
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        return 0;
    }
}
