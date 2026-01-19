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

/** @internal */
final class CallbackChainGuardTest extends TestCase
{
    public function testChainStopsWithoutAllowChain(): void
    {
        $grandChildExecuted = false;

        $parent = new Job(job: 'closure', payload: static fn () => 'PARENT');
        $parent->setCallbackJob(static function (Job $p) use (&$grandChildExecuted) {
            $child = new Job(job: 'closure', payload: static fn () => 'CHILD');
            // Intenta definir callback en el hijo (no debería ejecutarse)
            $child->setCallbackJob(static function (Job $c) use (&$grandChildExecuted) {
                $grandChildExecuted = true;

                return new Job(job: 'closure', payload: static fn () => 'GRAND');
            });

            return $child; // sin allowChain en padre
        });

        (new JobLifecycleCoordinator())->run($parent, 'queue');
        $this->assertFalse($grandChildExecuted, 'Grandchild callback executed unexpectedly without allowChain');
    }

    public function testChainContinuesWithAllowChain(): void
    {
        $grandChildExecuted = false;

        $parent = new Job(job: 'closure', payload: static fn () => 'PARENT');
        $parent->setCallbackJob(static function (Job $p) use (&$grandChildExecuted) {
            $child = new Job(job: 'closure', payload: static fn () => 'CHILD');
            $child->setCallbackJob(static function (Job $c) use (&$grandChildExecuted) {
                $grandChildExecuted = true;

                return new Job(job: 'closure', payload: static fn () => 'GRAND');
            });

            return $child;
        }, ['allowChain' => true]);

        (new JobLifecycleCoordinator())->run($parent, 'queue');
        $this->assertTrue($grandChildExecuted, 'Grandchild callback did not execute with allowChain');
    }
}
