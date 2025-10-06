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

/** @internal */
final class CallbackFilterTest extends TestCase
{
    private function context(): ExecutionContext
    {
        return new ExecutionContext(
            source: 'queue',
            maxRetries: 0,
            notifyOnSuccess: false,
            notifyOnFailure: false,
            singleInstance: false,
            queueName: 'default',
            queueWorker: null,
            retryConfig: [
                'strategy'   => 'none',
                'base'       => 0,
                'multiplier' => 1,
                'jitter'     => false,
                'max'        => 0,
            ],
            eventsEnabled: false,
            meta: [],
        );
    }

    public function testCallbackRunsOnlyOnSuccess(): void
    {
        $ran = false;
        $j   = new Job(job: 'closure', payload: static fn () => 'OK');
        $j->setCallbackJob(static function (Job $p) use (&$ran) {
            $ran = true;

            return new Job(job: 'closure', payload: static fn () => 'child');
        }, ['on' => 'success']);
        (new JobLifecycleCoordinator())->run($j, $this->context());
        $this->assertTrue($ran);
    }

    public function testCallbackSkipsOnSuccessWhenFailureFilter(): void
    {
        $ran = false;
        $j   = new Job(job: 'closure', payload: static fn () => 'OK');
        $j->setCallbackJob(static function (Job $p) use (&$ran) {
            $ran = true;

            return new Job(job: 'closure', payload: static fn () => 'child');
        }, ['on' => 'failure']);
        (new JobLifecycleCoordinator())->run($j, $this->context());
        $this->assertFalse($ran);
    }

    public function testCallbackRunsOnFailureWithAliasError(): void
    {
        $ran = false;
        $j   = new Job(job: 'closure', payload: static fn () => throw new RuntimeException('fail'));
        $j->setCallbackJob(static function (Job $p) use (&$ran) {
            $ran = true;

            return new Job(job: 'closure', payload: static fn () => 'child');
        }, ['on' => 'error']);
        (new JobLifecycleCoordinator())->run($j, $this->context());
        $this->assertTrue($ran);
    }

    public function testCallbackAlways(): void
    {
        $ran = false;
        $j   = new Job(job: 'closure', payload: static fn () => throw new RuntimeException('fail'));
        $j->setCallbackJob(static function (Job $p) use (&$ran) {
            $ran = true;

            return new Job(job: 'closure', payload: static fn () => 'child');
        }); // default always
        (new JobLifecycleCoordinator())->run($j, $this->context());
        $this->assertTrue($ran);
    }
}
