<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Cronjob\Scheduler;

final class SchedulerTest extends TestCase
{
    public function testDependencyOrder(): void
    {
        $scheduler = new Scheduler();
        $scheduler->command('cmd:a')->named('A');
        $scheduler->command('cmd:b')->named('B')->dependsOn('A');
        $scheduler->command('cmd:c')->named('C')->dependsOn(['A','B']);

        $order = $scheduler->getExecutionOrder();
        $names = array_map(fn($j) => $j->getName(), $order);
        $this->assertSame(['A','B','C'], $names);
    }
}
