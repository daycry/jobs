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

// Dynamic test handlers configured at runtime
class _ExecutorTestSuccessHandler extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle($payload)
    {
        echo 'buffered';

        return 'returned';
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}
class _ExecutorTestArrayHandler extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle($payload)
    {
        return ['a' => 1];
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}
class _ExecutorTestExceptionHandler extends Job
{
    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function handle($payload): void
    {
        throw new RuntimeException('boom');
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}

/**
 * @internal
 */
final class JobInternalExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                          = config('Jobs');
        $cfg->jobs['_exec_success']   = _ExecutorTestSuccessHandler::class;
        $cfg->jobs['_exec_array']     = _ExecutorTestArrayHandler::class;
        $cfg->jobs['_exec_exception'] = _ExecutorTestExceptionHandler::class;
        // Asegurar que no intenta usar logging en DB en estos tests unitarios
        $cfg->logPerformance = false;
        $cfg->log            = 'file';
    }

    public function testSuccessMergesBufferAndReturn(): void
    {
        $job         = new Job(job: '_exec_success', payload: 'x');
        $coordinator = new JobLifecycleCoordinator();
        $exec        = $coordinator->run($job)->finalResult;
        $this->assertTrue($exec->success);
        // Expect returned + \n? handled by merge logic -> returned\n?buffered or returnedbuffered depending on newline logic
        $this->assertStringContainsString('returned', $exec->output ?? '');
        $this->assertStringContainsString('buffered', $exec->output ?? '');
        $this->assertNull($exec->error);
    }

    public function testArrayReturnNormalizedJson(): void
    {
        $job         = new Job(job: '_exec_array', payload: null);
        $coordinator = new JobLifecycleCoordinator();
        $exec        = $coordinator->run($job)->finalResult;
        $this->assertTrue($exec->success);
        $this->assertSame('{"a":1}', $exec->output);
    }

    public function testExceptionPath(): void
    {
        $job         = new Job(job: '_exec_exception', payload: null);
        $coordinator = new JobLifecycleCoordinator();
        $exec        = $coordinator->run($job)->finalResult;
        $this->assertFalse($exec->success);
        $this->assertNull($exec->output);
        $this->assertSame('boom', $exec->error);
    }
}
