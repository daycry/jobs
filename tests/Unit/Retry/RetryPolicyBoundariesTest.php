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
final class RetryPolicyBoundariesTest extends TestCase
{
    public function testMaxRetriesZero(): void
    {
        $cfg                         = config('Jobs');
        $cfg->retryBackoffStrategy   = 'fixed';
        $cfg->retryBackoffBase       = 5;
        $cfg->retryBackoffMultiplier = 2.0;
        $cfg->retryBackoffJitter     = false;
        $cfg->retryBackoffMax        = 30;

        $sleeps = [];
        $job    = new Job(job: 'closure', payload: static function (): void { throw new RuntimeException('fail'); });
        $job->named('max0');
        $ctx = new ExecutionContext(
            source: 'queue',
            maxRetries: 0,
            notifyOnSuccess: false,
            notifyOnFailure: false,
            singleInstance: false,
            queueName: 'default',
            queueWorker: null,
            retryConfig: [
                'strategy'   => 'fixed',
                'base'       => 5,
                'multiplier' => 2.0,
                'jitter'     => false,
                'max'        => 30,
            ],
            eventsEnabled: false,
            meta: [],
        );
        $coordinator = new JobLifecycleCoordinator(sleeper: static function (int $s) use (&$sleeps): void { $sleeps[] = $s; });
        $outcome     = $coordinator->run($job, $ctx);
        $this->assertSame(1, $outcome->attempts);
        $this->assertEmpty($sleeps);
    }

    public function testMaxRetriesOne(): void
    {
        $cfg                         = config('Jobs');
        $cfg->retryBackoffStrategy   = 'fixed';
        $cfg->retryBackoffBase       = 2;
        $cfg->retryBackoffMultiplier = 2.0;
        $cfg->retryBackoffJitter     = false;
        $cfg->retryBackoffMax        = 30;

        $sleeps = [];
        $job    = new Job(job: 'closure', payload: static function (): void { throw new RuntimeException('fail'); });
        $job->named('max1');
        $ctx = new ExecutionContext(
            source: 'queue',
            maxRetries: 1,
            notifyOnSuccess: false,
            notifyOnFailure: false,
            singleInstance: false,
            queueName: 'default',
            queueWorker: null,
            retryConfig: [
                'strategy'   => 'fixed',
                'base'       => 2,
                'multiplier' => 2.0,
                'jitter'     => false,
                'max'        => 30,
            ],
            eventsEnabled: false,
            meta: [],
        );
        $coordinator = new JobLifecycleCoordinator(sleeper: static function (int $s) use (&$sleeps): void { $sleeps[] = $s; });
        $outcome     = $coordinator->run($job, $ctx);
        $this->assertSame(2, $outcome->attempts); // initial + 1 retry
        $this->assertSame([2], $sleeps); // fixed delay base
    }
}
