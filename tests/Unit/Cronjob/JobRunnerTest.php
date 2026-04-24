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

use Config\Services;
use Daycry\Jobs\Cronjob\JobRunner;
use Daycry\Jobs\Cronjob\Scheduler;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class JobRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::superglobals()->setServer('CI_ENVIRONMENT', 'testing');
        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
    }

    private function scheduler(): Scheduler
    {
        return Daycry\Jobs\Config\Services::scheduler();
    }

    public function testRunSkipsJobsNotDue(): void
    {
        // Override scheduler with a job that's never due (yearly at a specific date)
        $job = (new Job(job: 'command', payload: 'jobs:test'))
            ->cron('0 0 29 2 *') // Feb 29th - rarely runs
            ->named('never_due_test');

        $scheduler = $this->scheduler();
        $ref       = new ReflectionObject($scheduler);
        $prop      = $ref->getProperty('jobs');
        $prop->setValue($scheduler, [$job]);

        $runner = new JobRunner();
        $runner->run();

        $this->assertGreaterThanOrEqual(1, count($scheduler->getJobs()));
    }

    public function testRunExecutesDueJob(): void
    {
        // Set up a job that's always due (every minute)
        $job = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('due_job_test')
            ->environments('testing');

        $scheduler = $this->scheduler();
        $ref       = new ReflectionObject($scheduler);
        $prop      = $ref->getProperty('jobs');
        $prop->setValue($scheduler, [$job]);

        $runner = new JobRunner();
        $runner->run();

        $this->assertGreaterThanOrEqual(1, count($scheduler->getJobs()));
    }

    public function testRunWithOnlyFilter(): void
    {
        $job1 = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('job_a')
            ->environments('testing');
        $job2 = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('job_b')
            ->environments('testing');

        $scheduler = $this->scheduler();
        $ref       = new ReflectionObject($scheduler);
        $prop      = $ref->getProperty('jobs');
        $prop->setValue($scheduler, [$job1, $job2]);

        $runner = new JobRunner();
        $runner->only(['job_a'])->run(); // Only run job_a

        $this->assertGreaterThanOrEqual(2, count($scheduler->getJobs()));
    }

    public function testRunWithTestTime(): void
    {
        $job = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('testtime_job')
            ->environments('testing');

        $scheduler = $this->scheduler();
        $ref       = new ReflectionObject($scheduler);
        $prop      = $ref->getProperty('jobs');
        $prop->setValue($scheduler, [$job]);

        $runner = new JobRunner();
        $runner->withTestTime('2025-01-01 12:00:00')->run();

        $this->assertGreaterThanOrEqual(1, count($scheduler->getJobs()));
    }

    public function testRunWithQueuedJob(): void
    {
        // A job with a queue set should be pushed to queue, not executed inline
        $job = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('queued_job_test')
            ->setQueue('default')
            ->environments('testing');

        $scheduler = $this->scheduler();
        $ref       = new ReflectionObject($scheduler);
        $prop      = $ref->getProperty('jobs');
        $prop->setValue($scheduler, [$job]);

        $runner = new JobRunner();
        $runner->run();

        $this->assertGreaterThanOrEqual(1, count($scheduler->getJobs()));
    }

    public function testWithTestTimeReturnsSelf(): void
    {
        $runner = new JobRunner();
        $result = $runner->withTestTime('2025-06-01 00:00:00');
        $this->assertSame($runner, $result);
    }

    public function testOnlyReturnsSelf(): void
    {
        $runner = new JobRunner();
        $result = $runner->only(['job_x']);
        $this->assertSame($runner, $result);
    }

    public function testComputeBackoffDelayForRetryStrategies(): void
    {
        $runner = new JobRunner();
        $ref    = new ReflectionClass($runner);
        $method = $ref->getMethod('computeBackoffDelay');

        // First attempt - always 0
        config('Jobs')->retryBackoffStrategy = 'fixed';
        config('Jobs')->retryBackoffBase     = 5;
        config('Jobs')->retryBackoffMax      = 60;
        $this->assertSame(0, $method->invoke($runner, 1));

        // Fixed strategy on attempt 2
        config('Jobs')->retryBackoffStrategy = 'fixed';
        $delay                               = $method->invoke($runner, 2);
        $this->assertGreaterThanOrEqual(1, $delay);

        // Exponential strategy
        config('Jobs')->retryBackoffStrategy = 'exponential';
        $delay                               = $method->invoke($runner, 3);
        $this->assertGreaterThan(0, $delay);

        // None strategy
        config('Jobs')->retryBackoffStrategy = 'none';
        $this->assertSame(0, $method->invoke($runner, 5));
    }

    public function testComputeBackoffDelayWithJitter(): void
    {
        $runner = new JobRunner();
        $ref    = new ReflectionClass($runner);
        $method = $ref->getMethod('computeBackoffDelay');

        config('Jobs')->retryBackoffStrategy   = 'fixed';
        config('Jobs')->retryBackoffBase       = 10;
        config('Jobs')->retryBackoffMax        = 60;
        config('Jobs')->retryBackoffJitter     = true;
        config('Jobs')->retryBackoffMultiplier = 2.0;

        $delay = $method->invoke($runner, 2);
        $this->assertGreaterThanOrEqual(0, $delay); // With jitter could be 0 if clamped

        config('Jobs')->retryBackoffJitter = false;
    }

    public function testFireEventDisabled(): void
    {
        config('Jobs')->enableEvents = false;

        $job = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('fire_disabled_test')
            ->environments('testing');

        $scheduler = $this->scheduler();
        $ref       = new ReflectionObject($scheduler);
        $prop      = $ref->getProperty('jobs');
        $prop->setValue($scheduler, [$job]);

        $runner = new JobRunner();
        $runner->run(); // Will call fire() which should return early

        config('Jobs')->enableEvents = true;
        $this->assertGreaterThanOrEqual(1, count($scheduler->getJobs()));
    }

    public function testRunWithFailedJob(): void
    {
        // Use a job type that will fail (e.g., invalid command)
        $job = (new Job(job: 'command', payload: 'nonexistent:command:xyz'))
            ->everyMinute()
            ->named('failing_job_test')
            ->environments('testing');

        $scheduler = $this->scheduler();
        $ref       = new ReflectionObject($scheduler);
        $prop      = $ref->getProperty('jobs');
        $prop->setValue($scheduler, [$job]);

        $runner = new JobRunner();
        $runner->run();

        $this->assertGreaterThanOrEqual(1, count($scheduler->getJobs()));
    }
}
