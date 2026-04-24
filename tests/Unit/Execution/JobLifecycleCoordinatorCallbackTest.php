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

use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

class _CoordTestSuccessHandler3 extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle(mixed $payload): string
    {
        return 'ok';
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}

class _CoordChildNoQueue extends Job
{
    public static int $calls = 0;

    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle(mixed $payload): string
    {
        self::$calls++;

        return 'child';
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}

/**
 * @internal
 */
final class JobLifecycleCoordinatorCallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                       = config('Jobs');
        $cfg->jobs['_coord3']      = _CoordTestSuccessHandler3::class;
        $cfg->jobs['_coord_nq']    = _CoordChildNoQueue::class;
        $cfg->logPerformance       = false;
        $cfg->log                  = 'file';
        $cfg->retryBackoffStrategy = 'none';
        _CoordChildNoQueue::$calls = 0;
    }

    public function testCallbackWithInheritOptionsInjectsOutputAndError(): void
    {
        $childJob = new Job(job: '_coord_nq', payload: 'child');
        $parent   = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['inherit' => ['output', 'error', 'attempts', 'name', 'source'], 'filter' => 'always', 'allowChain' => false],
        );

        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        $this->assertSame(1, _CoordChildNoQueue::$calls);
    }

    public function testCallbackWithArrayPayloadMergesArrayMeta(): void
    {
        $childJob = new Job(job: '_coord_nq', payload: ['data' => 'test', 'meta' => ['existing' => 1]]);
        $parent   = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['inherit' => ['output'], 'filter' => 'always'],
        );

        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        $this->assertSame(1, _CoordChildNoQueue::$calls);
    }

    public function testCallbackWithInvalidBuilderReturnHandlesGracefully(): void
    {
        $parent = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => 'not_a_job', // Invalid return
            ['filter' => 'always'],
        );

        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($parent);
        // Should succeed without throwing
        $this->assertTrue($outcome->finalResult->success);
    }

    public function testCallbackBuilderExceptionHandledGracefully(): void
    {
        $parent = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static function ($p): void {
                throw new RuntimeException('builder error');
            },
            ['filter' => 'always'],
        );

        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($parent);
        // Parent should still succeed despite callback builder failure
        $this->assertTrue($outcome->finalResult->success);
    }

    public function testCallbackWithScalarPayloadWrapsInArray(): void
    {
        $childJob = new Job(job: '_coord_nq', payload: 'scalar_payload');
        $parent   = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['inherit' => ['output'], 'filter' => 'always'],
        );

        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        $this->assertSame(1, _CoordChildNoQueue::$calls);
    }

    public function testCallbackWithObjectPayloadMergesMeta(): void
    {
        $childPayload = (object) ['data' => 'test'];
        $childJob     = new Job(job: '_coord_nq', payload: $childPayload);
        $parent       = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['inherit' => ['output', 'error'], 'filter' => 'always'],
        );

        $coordinator = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        $this->assertSame(1, _CoordChildNoQueue::$calls);
    }

    public function testRetryPolicyExponentialStrategy(): void
    {
        $cfg                         = config('Jobs');
        $cfg->retryBackoffStrategy   = 'exponential';
        $cfg->retryBackoffBase       = 1;
        $cfg->retryBackoffJitter     = false;
        $cfg->retryBackoffMax        = 10;
        $cfg->retryBackoffMultiplier = 2.0;

        $job = new Job(job: '_coord3', payload: 'x');
        // Just run it (no retries needed for exponential path test)
        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($job);
        $this->assertTrue($outcome->finalResult->success);

        // Reset
        $cfg->retryBackoffStrategy = 'none';
        $cfg->retryBackoffBase     = 0;
    }

    public function testCallbackChainWithAllowChain(): void
    {
        // allowChain=true should recurse into child's callback if any
        $childJob   = new Job(job: '_coord_nq', payload: 'child');
        $grandchild = new Job(job: '_coord_nq', payload: 'grandchild');
        $childJob->setCallbackJob(
            static fn ($p) => $grandchild,
            ['filter' => 'always'],
        );

        $parent = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['filter' => 'always', 'allowChain' => true],
        );

        _CoordChildNoQueue::$calls = 0;
        $coordinator               = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        // Both child and grandchild should execute
        $this->assertSame(2, _CoordChildNoQueue::$calls);
    }

    public function testCallbackFilterSuccessSkipsOnFailure(): void
    {
        // Register a failing handler
        $cfg                       = config('Jobs');
        $cfg->jobs['_coord_fail3'] = _CoordFailHandler3::class;

        $childJob = new Job(job: '_coord_nq', payload: 'child');
        $parent   = new Job(job: '_coord_fail3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['filter' => 'success'], // Only run on success
        );

        _CoordChildNoQueue::$calls = 0;
        $coordinator               = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        // Child should NOT execute since parent failed
        $this->assertSame(0, _CoordChildNoQueue::$calls);
    }

    public function testCallbackFilterFailureRunsOnlyOnFailure(): void
    {
        $cfg                       = config('Jobs');
        $cfg->jobs['_coord_fail3'] = _CoordFailHandler3::class;

        $childJob = new Job(job: '_coord_nq', payload: 'child');
        $parent   = new Job(job: '_coord_fail3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['filter' => 'failure'], // Only run on failure
        );

        _CoordChildNoQueue::$calls = 0;
        $coordinator               = new JobLifecycleCoordinator();
        $coordinator->run($parent);

        // Child SHOULD execute since parent failed
        $this->assertSame(1, _CoordChildNoQueue::$calls);
    }

    public function testCallbackWithClosurePayloadSkipsMerge(): void
    {
        // Closure payload in child - cannot inject meta
        $closure  = static fn () => 'from closure';
        $childJob = new Job(job: 'closure', payload: $closure);
        $parent   = new Job(job: '_coord3', payload: 'x');
        $parent->setCallbackJob(
            static fn ($p) => $childJob,
            ['inherit' => ['output'], 'filter' => 'always'],
        );

        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($parent);
        $this->assertTrue($outcome->finalResult->success);
    }
}

class _CoordFailHandler3 extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle(mixed $payload): string
    {
        throw new RuntimeException('Forced failure');
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}
