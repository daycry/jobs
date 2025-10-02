<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;

final class JobBasicTest extends TestCase
{
    public function testJobStoresPayloadAndName(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('my_command');

        $this->assertSame('command', $job->getJob());
        $this->assertSame('jobs:test', $job->getPayload());
        $this->assertSame('my_command', $job->getName());
    }
}
