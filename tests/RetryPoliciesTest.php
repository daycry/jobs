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

use Daycry\Jobs\Execution\ExecutionContext;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class RetryPoliciesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                         = config('Jobs');
        $cfg->logPerformance         = false;
        $cfg->log                    = 'file';
        $cfg->retryBackoffStrategy   = 'fixed';
        $cfg->retryBackoffBase       = 1; // second
        $cfg->retryBackoffMultiplier = 2.0;
        $cfg->retryBackoffJitter     = false;
        $cfg->retryBackoffMax        = 5;
    }

    public function testFixedRetryStopsAfterMaxRetries(): void
    {
        // handler that always throws
        $job = new Job(job: 'closure', payload: static function (): void { throw new RuntimeException('fail'); });
        $job->named('retry_fixed');
        $ctx = new ExecutionContext(
            source: 'queue',
            maxRetries: 2, // attempts: first + 2 retries = 3 executions
            notifyOnSuccess: false,
            notifyOnFailure: false,
            singleInstance: false,
            queueName: 'default',
            queueWorker: null,
            retryConfig: [
                'strategy'   => 'fixed',
                'base'       => 1,
                'multiplier' => 2.0,
                'jitter'     => false,
                'max'        => 5,
            ],
            eventsEnabled: false,
            meta: [],
        );
        $coordinator = new JobLifecycleCoordinator();
        $outcome     = $coordinator->run($job, $ctx);
        $this->assertFalse($outcome->finalResult->success);
        $this->assertSame(3, $outcome->attempts, 'Should execute initial + 2 retries');
    }
}
