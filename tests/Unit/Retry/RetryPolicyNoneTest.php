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
final class RetryPolicyNoneTest extends TestCase
{
    public function testNoDelayButRetriesStillOccurWithStrategyNone(): void
    {
        $cfg                         = config('Jobs');
        $cfg->retryBackoffStrategy   = 'none';
        $cfg->retryBackoffBase       = 1;
        $cfg->retryBackoffMultiplier = 2.0;
        $cfg->retryBackoffJitter     = false;
        $cfg->retryBackoffMax        = 10;

        $attemptsSleeps = [];
        $sleeper        = static function (int $seconds) use (&$attemptsSleeps): void { $attemptsSleeps[] = $seconds; };

        $job = new Job(job: 'closure', payload: static function (): void { throw new RuntimeException('fail'); });
        $job->named('none_strategy');
        $ctx = new ExecutionContext(
            source: 'queue',
            maxRetries: 5,
            notifyOnSuccess: false,
            notifyOnFailure: false,
            singleInstance: false,
            queueName: 'default',
            queueWorker: null,
            retryConfig: [
                'strategy'   => 'none',
                'base'       => 1,
                'multiplier' => 2.0,
                'jitter'     => false,
                'max'        => 10,
            ],
            eventsEnabled: false,
            meta: [],
        );

        $coordinator = new JobLifecycleCoordinator(sleeper: $sleeper);
        $outcome     = $coordinator->run($job, $ctx);
        $this->assertSame(6, $outcome->attempts, 'Initial + maxRetries attempts expected');
        $this->assertEmpty($attemptsSleeps, 'Sleeper should not be invoked (all delays zero)');
    }
}
