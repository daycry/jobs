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
final class RetryPolicyJitterTest extends TestCase
{
    public function testJitterWithinRange(): void
    {
        $cfg                         = config('Jobs');
        $cfg->retryBackoffStrategy   = 'exponential';
        $cfg->retryBackoffBase       = 4; // base
        $cfg->retryBackoffMultiplier = 2.0; // growth
        $cfg->retryBackoffJitter     = true;
        $cfg->retryBackoffMax        = 100;

        $delays  = [];
        $sleeper = static function (int $seconds) use (&$delays): void { $delays[] = $seconds; };

        $failCount = 0;
        $job       = new Job(job: 'closure', payload: static function () use (&$failCount) {
            $failCount++;
            if ($failCount <= 3) {
                throw new RuntimeException('fail');
            }

            return 'done';
        });
        $job->named('jitter_strategy');
        $job->maxRetries(5);

        $coordinator = new JobLifecycleCoordinator(sleeper: $sleeper);
        $coordinator->run($job, 'queue');

        // Expected theoretical base delays without jitter: attempt transitions -> 4, 8, 16
        $expected = [4, 8, 16];
        $this->assertCount(3, $delays);

        foreach ($delays as $i => $d) {
            $base  = $expected[$i];
            $lower = max(1, (int) floor($base * 0.85));
            $upper = (int) ceil($base * 1.15);
            $this->assertTrue($d >= $lower && $d <= $upper, "Delay {$d} outside jitter range [{$lower},{$upper}] for base {$base}");
        }
    }
}
