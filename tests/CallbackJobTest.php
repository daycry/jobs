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
final class CallbackJobTest extends TestCase
{
    public function testInlineCallbackExecutesImmediately(): void
    {
        $parent = new Job(job: 'closure', payload: static fn () => 'PARENT_OK');
        $flag   = false;
        $parent->setCallbackJob(static function (Job $parent) use (&$flag) {
            $child = new Job(job: 'closure', payload: static function () use (&$flag) {
                $flag = true;

                return 'CHILD_OK';
            });

            return $child; // no queue => inline
        });

        $coordinator = new JobLifecycleCoordinator();
        $ctx         = new ExecutionContext(
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
        $coordinator->run($parent, $ctx);
        $this->assertTrue($flag, 'Inline callback did not execute');
    }

    public function testEnqueuedCallbackDoesNotRunInline(): void
    {
        // Force a non-sync worker so that push() does not execute immediately
        $cfg         = config('Jobs');
        $cfg->worker = 'database';

        $executed = false;
        $childJob = new Job(job: 'closure', payload: static function () use (&$executed) {
            $executed = true; // should not run inline

            return 'CHILD';
        });
        $childJob->enqueue('default');

        $parent = new Job(job: 'closure', payload: static fn () => 'BASE');
        $parent->setCallbackJob(static function (Job $p) use ($childJob) {
            return $childJob; // has queue => will be pushed, not executed inline
        });

        // Stub executor to detect only parent execution
        $coordinator = new JobLifecycleCoordinator();
        $ctx         = new ExecutionContext(
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
        $coordinator->run($parent, $ctx);
        $this->assertFalse($executed, 'Enqueued callback executed inline unexpectedly');
    }
}
