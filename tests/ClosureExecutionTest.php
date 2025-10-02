<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;
use Daycry\Jobs\Execution\JobExecutor;

final class ClosureExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Asegurar que no intente loggear a DB
        config('Jobs')->logPerformance = false;
        config('Jobs')->log = 'file';
    }

    public function testClosureJobExecutes(): void
    {
        $job = new Job(job: 'closure', payload: fn() => 'OK');
        $job->named('closure_ok');
        $executor = new JobExecutor();
        $result = $executor->execute($job);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('OK', $result->getData());
    }
}
