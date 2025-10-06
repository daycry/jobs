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

use CodeIgniter\Exceptions\RuntimeException;
use Daycry\Jobs\Cronjob\Scheduler;
use Daycry\Jobs\Exceptions\JobException;
use Tests\Support\TestCase;

/** @internal */
final class SchedulerAdvancedTest extends TestCase
{
    public function testFindAndClearJobs(): void
    {
        $s = new Scheduler();
        $s->command('job:one')->named('one');
        $s->command('job:two')->named('two');
        $this->assertNotNull($s->findJobByName('one'));
        $this->assertNull($s->findJobByName('missing'));
        $this->assertCount(2, $s->getJobs());
        $s->clearJobs();
        $this->assertCount(0, $s->getJobs());
    }

    public function testInvalidJobTypeThrows(): void
    {
        $this->expectException(JobException::class);
        $s = new Scheduler();
        // Force invalid mapping by temporarily removing a known key
        $cfg      = config('Jobs');
        $original = $cfg->jobs['command'];
        unset($cfg->jobs['command']);

        try {
            $s->command('cmd:bad');
        } finally {
            // restore
            $cfg->jobs['command'] = $original;
        }
    }

    public function testMissingDependencyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $s = new Scheduler();
        $s->command('cmd:a')->named('A')->dependsOn('B'); // B no existe
        $s->getExecutionOrder();
    }

    public function testCircularDependencyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $s = new Scheduler();
        $s->command('cmd:a')->named('A')->dependsOn('C');
        $s->command('cmd:b')->named('B')->dependsOn('A');
        $s->command('cmd:c')->named('C')->dependsOn('B'); // ciclo A->C->B->A
        $s->getExecutionOrder();
    }
}
