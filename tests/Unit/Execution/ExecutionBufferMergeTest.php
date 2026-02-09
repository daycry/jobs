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

/**
 * @internal
 */
final class ExecutionBufferMergeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config('Jobs')->logPerformance = false;
        config('Jobs')->log            = 'file';
    }

    public function testBufferAndReturnAreMerged(): void
    {
        $job = new Job(job: 'closure', payload: static function () {
            echo 'SIDE'; // goes to output buffer

            return 'MAIN';
        });
        $job->named('buffer_merge');
        $coordinator = new JobLifecycleCoordinator();
        $result      = $coordinator->run($job)->finalResult;
        $this->assertTrue($result->success);
        // Expect MAIN + newline + SIDE or MAIN directly followed by SIDE depending on buffer newline
        $this->assertSame("MAIN\nSIDE", $result->output, 'Merged output should combine return + buffered echo');
    }

    public function testOnlyBufferWithoutReturn(): void
    {
        $job = new Job(job: 'closure', payload: static function () {
            echo 'ONLY';

            return null; // trigger assign buffer branch
        });
        $job->named('buffer_only');
        $coordinator = new JobLifecycleCoordinator();
        $result      = $coordinator->run($job)->finalResult;
        $this->assertTrue($result->success);
        $this->assertSame('ONLY', $result->output);
    }
}
