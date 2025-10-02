<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;

final class EnqueueTest extends TestCase
{
    public function testEnqueueAssignsQueue(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        // Forzamos config de colas simulada
        config('Jobs')->queues = 'default';
        $job->enqueue('default');
        $this->assertSame('default', $job->getQueue());
    }
}
