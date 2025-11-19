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

use Daycry\Jobs\Execution\JobExecutor;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class ClosureExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Asegurar que no intente loggear a DB
        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
    }

    public function testClosureJobExecutes(): void
    {
        $job = new Job(job: 'closure', payload: static fn () => 'OK');
        $job->named('closure_ok');
        $executor = new JobExecutor();
        $result   = $executor->execute($job); // ExecutionResult
        $this->assertTrue($result->success);
        $this->assertSame('OK', $result->output);
    }
}
