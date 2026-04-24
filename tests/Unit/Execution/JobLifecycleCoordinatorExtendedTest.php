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

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

// Handlers registered dynamically

class _CoordTestSuccessHandler2 extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle(mixed $payload): string
    {
        return 'success!';
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}

class _CoordTestFailureHandler extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle(mixed $payload): void
    {
        throw new RuntimeException('fail');
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}

class _CoordThrowsInHandleWithBufferHandler extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle(mixed $payload): never
    {
        echo 'some output'; // triggers buffer

        throw new RuntimeException('failed during output buffering');
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}

class _CoordCallbackChildHandler extends Job
{
    public static int $callCount = 0;

    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle(mixed $payload): string
    {
        self::$callCount++;

        return 'callback-ran';
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}

/**
 * @internal
 */
final class JobLifecycleCoordinatorExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                                   = config('Jobs');
        $cfg->jobs['_coord_success']           = _CoordTestSuccessHandler2::class;
        $cfg->jobs['_coord_failure']           = _CoordTestFailureHandler::class;
        $cfg->jobs['_coord_cb_child']          = _CoordCallbackChildHandler::class;
        $cfg->jobs['_coord_buf_throw']         = _CoordThrowsInHandleWithBufferHandler::class;
        $cfg->logPerformance                   = false;
        $cfg->log                              = 'file';
        $cfg->retryBackoffStrategy             = 'none';
        $cfg->retryBackoffBase                 = 0;
        _CoordCallbackChildHandler::$callCount = 0;
    }

    public function testRunReturnsLifecycleOutcome(): void
    {
        $job         = new Job(job: '_coord_success', payload: 'x');
        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($job);
        $this->assertTrue($outcome->finalResult->success);
        $this->assertFalse($outcome->finalFailure);
        $this->assertSame(1, $outcome->attempts);
    }

    public function testRunWithRetryRetriesCorrectly(): void
    {
        $job = new Job(job: '_coord_failure', payload: 'x');
        $job->maxRetries(2); // Will try up to 3 times total

        $sleptSeconds = [];
        $sleeper      = static function (int $s) use (&$sleptSeconds): void {
            $sleptSeconds[] = $s;
        };

        $coordinator = new JobLifecycleCoordinator($sleeper);
        $outcome     = $coordinator->run($job);
        $this->assertFalse($outcome->finalResult->success);
        $this->assertTrue($outcome->finalFailure);
        $this->assertSame(3, $outcome->attempts);
    }

    public function testRunWithNoRetriesFailsImmediately(): void
    {
        $job         = new Job(job: '_coord_failure', payload: 'x');
        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($job);
        $this->assertFalse($outcome->finalResult->success);
        $this->assertTrue($outcome->finalFailure);
        $this->assertSame(1, $outcome->attempts);
    }

    public function testRunWithSingleInstanceReleasesFlag(): void
    {
        $job = new Job(job: '_coord_success', payload: 'x');
        $job->named('single_test_job')->singleInstance();
        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($job);
        // After run, the running flag should be cleared
        $this->assertFalse($job->isRunning());
    }

    public function testSingleInstanceThrowsWhenAlreadyRunning(): void
    {
        $this->expectException(RuntimeException::class);
        $job = new Job(job: '_coord_success', payload: 'x');
        $job->named('already_running_job')->singleInstance();
        $job->saveRunningFlag(); // simulate it's already running

        try {
            $coordinator = new JobLifecycleCoordinator();
            $coordinator->run($job);
        } finally {
            $job->clearRunningFlag(); // cleanup
        }
    }

    public function testRunWithTimeoutOption(): void
    {
        $job = new Job(job: '_coord_success', payload: 'x');
        $job->timeout(60); // 60 second timeout
        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($job);
        // Should succeed (no actual timeout occurs for fast job)
        $this->assertTrue($outcome->finalResult->success);
    }

    public function testRunWithDefaultConfigTimeout(): void
    {
        $cfg                 = config('Jobs');
        $cfg->defaultTimeout = 60;
        $job                 = new Job(job: '_coord_success', payload: 'x');
        $coordinator         = new JobLifecycleCoordinator();
        $outcome             = $coordinator->run($job);
        $this->assertTrue($outcome->finalResult->success);
        $cfg->defaultTimeout = null; // reset
    }

    public function testCallbackJobDispatchedOnSuccess(): void
    {
        _CoordCallbackChildHandler::$callCount = 0;
        $childJob                              = new Job(job: '_coord_cb_child', payload: 'child');

        $parent = new Job(job: '_coord_success', payload: 'x');
        $parent->then($childJob); // success callback

        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        $this->assertSame(1, _CoordCallbackChildHandler::$callCount);
    }

    public function testCallbackJobNotDispatchedOnFailureWhenFilterIsSuccess(): void
    {
        _CoordCallbackChildHandler::$callCount = 0;
        $childJob                              = new Job(job: '_coord_cb_child', payload: 'child');

        $parent = new Job(job: '_coord_failure', payload: 'x');
        $parent->then($childJob); // success only callback

        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        // callback should NOT run since parent failed and filter is 'success'
        $this->assertSame(0, _CoordCallbackChildHandler::$callCount);
    }

    public function testCallbackJobDispatchedOnFailure(): void
    {
        _CoordCallbackChildHandler::$callCount = 0;
        $childJob                              = new Job(job: '_coord_cb_child', payload: 'child');

        $parent = new Job(job: '_coord_failure', payload: 'x');
        $parent->catch($childJob); // failure callback

        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        $this->assertSame(1, _CoordCallbackChildHandler::$callCount);
    }

    public function testCallbackJobWithAlwaysFilterRunsRegardlessOfOutcome(): void
    {
        _CoordCallbackChildHandler::$callCount = 0;
        $childSuccess                          = new Job(job: '_coord_cb_child', payload: 'child_s');
        $parentSuccess                         = new Job(job: '_coord_success', payload: 'x');
        $parentSuccess->finally($childSuccess);
        (new JobLifecycleCoordinator())->run($parentSuccess);
        $this->assertSame(1, _CoordCallbackChildHandler::$callCount);

        _CoordCallbackChildHandler::$callCount = 0;
        $childFailure                          = new Job(job: '_coord_cb_child', payload: 'child_f');
        $parentFailure                         = new Job(job: '_coord_failure', payload: 'x');
        $parentFailure->finally($childFailure);
        (new JobLifecycleCoordinator())->run($parentFailure);
        $this->assertSame(1, _CoordCallbackChildHandler::$callCount);
    }

    public function testRetryWithBackoffCallsSleeper(): void
    {
        $cfg                       = config('Jobs');
        $cfg->retryBackoffStrategy = 'fixed';
        $cfg->retryBackoffBase     = 1;
        $cfg->retryBackoffJitter   = false;
        $cfg->retryBackoffMax      = 10;

        $job = new Job(job: '_coord_failure', payload: 'x');
        $job->maxRetries(1);

        $sleptSeconds = [];
        $sleeper      = static function (int $s) use (&$sleptSeconds): void {
            $sleptSeconds[] = $s;
        };
        $coordinator = new JobLifecycleCoordinator($sleeper);
        $coordinator->run($job);

        // Should have slept once between attempt 1 and 2
        $this->assertCount(1, $sleptSeconds);
        $this->assertGreaterThan(0, $sleptSeconds[0]);

        // Reset
        $cfg->retryBackoffStrategy = 'none';
        $cfg->retryBackoffBase     = 0;
    }

    public function testSafeExecuteWithTimeoutZeroFallsBackToSafeExecute(): void
    {
        // Direct test of safeExecuteWithTimeout with timeout=0 (covers dead-code guard)
        $coordinator = new JobLifecycleCoordinator();
        $job         = new Job(job: '_coord_success', payload: 'x');

        $ref    = new ReflectionClass($coordinator);
        $method = $ref->getMethod('safeExecuteWithTimeout');
        $result = $method->invoke($coordinator, $job, 0);

        $this->assertInstanceOf(ExecutionResult::class, $result);
        $this->assertTrue($result->success);
    }

    public function testExecuteJobInternalHandlesExceptionWithBufferActive(): void
    {
        // This handler echoes output then throws, testing the ob_end_clean path
        $coordinator = new JobLifecycleCoordinator();
        $job         = new Job(job: '_coord_buf_throw', payload: 'x');

        $outcome = $coordinator->run($job);
        // Should record failure, not throw
        $this->assertFalse($outcome->finalResult->success);
        $this->assertStringContainsString('failed during output buffering', (string) $outcome->finalResult->error);
    }

    public function testSafeExecuteCatchesThrowableFromLoggerInCatchBlock(): void
    {
        $cfg                 = config('Jobs');
        $originalLog         = $cfg->log;
        $originalLogging     = $cfg->logPerformance;
        $cfg->logPerformance = true;
        $cfg->log            = 'invalid_log_type_for_test';

        $coordinator = new JobLifecycleCoordinator();
        $job         = new Job(job: '_coord_failure', payload: 'x');
        $outcome     = $coordinator->run($job);

        $this->assertFalse($outcome->finalResult->success);
        $this->assertIsString($outcome->finalResult->error);
        $this->assertNotSame('', $outcome->finalResult->error);

        $cfg->log            = $originalLog;
        $cfg->logPerformance = $originalLogging;
    }

    public function testRunWithInvalidMappedHandlerClassReturnsFailure(): void
    {
        $cfg                             = config('Jobs');
        $cfg->jobs['_coord_invalid_map'] = stdClass::class;

        $coordinator = new JobLifecycleCoordinator();
        $job         = new Job(job: '_coord_invalid_map', payload: 'x');
        $outcome     = $coordinator->run($job);

        $this->assertFalse($outcome->finalResult->success);
        $this->assertIsString($outcome->finalResult->error);
    }

    public function testNormalizeOutputReturnsNullForNullInput(): void
    {
        $coordinator = new JobLifecycleCoordinator();
        $ref         = new ReflectionClass($coordinator);
        $method      = $ref->getMethod('normalizeOutput');

        $result = $method->invoke($coordinator, null);

        $this->assertNull($result);
    }
}
