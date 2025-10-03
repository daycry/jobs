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

use Daycry\Jobs\Cronjob\Scheduler;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class SchedulerTest extends TestCase
{
    public function testDependencyOrder(): void
    {
        $scheduler = new Scheduler();
        $scheduler->command('cmd:a')->named('A');
        $scheduler->command('cmd:b')->named('B')->dependsOn('A');
        $scheduler->command('cmd:c')->named('C')->dependsOn(['A', 'B']);

        $order = $scheduler->getExecutionOrder();
        $names = array_map(static fn ($j) => $j->getName(), $order);
        $this->assertSame(['A', 'B', 'C'], $names);
    }
}
