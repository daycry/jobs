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
final class RetryPolicyExponentialTest extends TestCase
{
    public function testExponentialDelaysWithoutJitter(): void
    {
        $cfg                         = config('Jobs');
        $cfg->retryBackoffStrategy   = 'exponential';
        $cfg->retryBackoffBase       = 2; // base
        $cfg->retryBackoffMultiplier = 3.0; // multiplier
        $cfg->retryBackoffJitter     = false;
        $cfg->retryBackoffMax        = 20; // cap

        $delays  = [];
        $sleeper = static function (int $seconds) use (&$delays): void { $delays[] = $seconds; };

        // Throw until after 3 retries then succeed
        $attemptCounter = 0;
        $job            = new Job(job: 'closure', payload: static function () use (&$attemptCounter) {
            $attemptCounter++;
            if ($attemptCounter <= 3) {
                throw new RuntimeException('fail');
            }

            return 'ok';
        });
        $job->named('exp_strategy');
        $job->maxRetries(5);

        $coordinator = new JobLifecycleCoordinator(sleeper: $sleeper);
        $outcome     = $coordinator->run($job, 'queue');

        // attempt sequence: 1(fail),2(fail),3(fail),4(success) -> 3 sleeps
        // Expected raw delays (attempt+1): for attempt 1 next attempt=2 exponent 0 => 2
        // attempt 2 next=3 exponent 1 => 2*3=6
        // attempt 3 next=4 exponent 2 => 2*3^2=18 (<= cap 20)
        $this->assertSame([2, 6, 18], $delays);
        $this->assertTrue($outcome->finalResult->success);
        $this->assertSame(4, $outcome->attempts);
    }

    public function testExponentialRespectsMaxCap(): void
    {
        $cfg                         = config('Jobs');
        $cfg->retryBackoffStrategy   = 'exponential';
        $cfg->retryBackoffBase       = 3;
        $cfg->retryBackoffMultiplier = 4.0;
        $cfg->retryBackoffJitter     = false;
        $cfg->retryBackoffMax        = 10; // cap low

        $delays  = [];
        $sleeper = static function (int $seconds) use (&$delays): void { $delays[] = $seconds; };

        $job = new Job(job: 'closure', payload: static function (): void { throw new RuntimeException('always'); });
        $job->named('exp_cap');
        $job->maxRetries(3);

        $coordinator = new JobLifecycleCoordinator(sleeper: $sleeper);
        $outcome     = $coordinator->run($job, 'queue');

        // attempts: 1,2,3,4 (fail final) -> sleeps between first 3 failures
        // next attempt delays: exponent sequence 0:3, 1:12->cap10, 2:48->cap10
        $this->assertSame([3, 10, 10], $delays);
        $this->assertFalse($outcome->finalResult->success);
        $this->assertSame(4, $outcome->attempts);
    }
}
