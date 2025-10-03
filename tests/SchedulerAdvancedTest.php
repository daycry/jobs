<?php

declare(strict_types=1);

use Daycry\Jobs\Cronjob\Scheduler;
use Daycry\Jobs\Exceptions\JobException;
use CodeIgniter\Exceptions\RuntimeException;
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
        $cfg = config('Jobs');
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
