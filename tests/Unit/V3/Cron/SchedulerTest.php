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

namespace Tests\Unit\V3\Cron;

use CodeIgniter\Exceptions\RuntimeException;
use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Cron\Scheduler;
use Daycry\Jobs\Definition\JobDefinition;

/**
 * @internal
 */
final class SchedulerTest extends CIUnitTestCase
{
    public function testDefineRegistersAndReturnsBuilder(): void
    {
        $scheduler = new Scheduler();
        $scheduler->define('command', 'jobs:test');

        $this->assertCount(1, $scheduler->getDefinitions());
    }

    public function testGetDefinitionsMaterialisesBuilders(): void
    {
        $scheduler = new Scheduler();
        $scheduler->define('command', 'jobs:test')->named('a')->dailyAt('02:00');

        $definitions = $scheduler->getDefinitions();
        $this->assertCount(1, $definitions);
        $this->assertSame('a', $definitions[0]->name);
        $this->assertSame('0 2 * * *', $definitions[0]->cronExpression);
    }

    public function testClearRemovesAllBuilders(): void
    {
        $scheduler = new Scheduler();
        $scheduler->define('command', 'jobs:test');
        $scheduler->clear();

        $this->assertSame([], $scheduler->getDefinitions());
    }

    public function testGetExecutionOrderRespectsDependencies(): void
    {
        $scheduler = new Scheduler();
        $scheduler->define('command', 'a')->named('a')->dependsOn('b');
        $scheduler->define('command', 'b')->named('b');

        $order = $scheduler->getExecutionOrder();
        $names = array_map(static fn (JobDefinition $d): ?string => $d->name, $order);

        $this->assertSame(['b', 'a'], $names);
    }

    public function testGetExecutionOrderThrowsOnMissingDependency(): void
    {
        $scheduler = new Scheduler();
        $scheduler->define('command', 'a')->named('a')->dependsOn('missing');

        $this->expectException(RuntimeException::class);
        $scheduler->getExecutionOrder();
    }

    public function testGetExecutionOrderThrowsOnCircularDependency(): void
    {
        $scheduler = new Scheduler();
        $scheduler->define('command', 'a')->named('a')->dependsOn('b');
        $scheduler->define('command', 'b')->named('b')->dependsOn('a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency');
        $scheduler->getExecutionOrder();
    }
}
