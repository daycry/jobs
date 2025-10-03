<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RequeueHelper;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RequeueHelperTest extends TestCase
{
    private function dummyJob(): Job
    {
        return new Job(job: 'command', payload: 'jobs:test');
    }

    public function testFinalizeSuccess(): void
    {
        $job      = $this->dummyJob();
        $envelope = JobEnvelope::fromJob($job, []);
        $metrics  = new InMemoryMetricsCollector();
        $removed  = [];
        $helper   = new RequeueHelper($metrics);
        $helper->finalize($job, $envelope, function ($j, $r) use (&$removed) {
            $removed[] = [$j, $r];
        }, true);

        $snapshot = $metrics->getSnapshot();
        $this->assertArrayHasKey('jobs_succeeded|queue=default', $snapshot['counters']);
        $this->assertSame(1, $snapshot['counters']['jobs_succeeded|queue=default']);
        $this->assertSame([[ $job, false ]], $removed);
    }

    public function testFinalizeFailureRequeues(): void
    {
        $job      = $this->dummyJob();
        $envelope = JobEnvelope::fromJob($job, []);
        $metrics  = new InMemoryMetricsCollector();
        $removed  = [];
        $helper   = new RequeueHelper($metrics);
        $helper->finalize($job, $envelope, function ($j, $r) use (&$removed) {
            $removed[] = [$j, $r];
        }, false);

        $snapshot = $metrics->getSnapshot();
        $this->assertArrayHasKey('jobs_failed|queue=default', $snapshot['counters']);
        $this->assertArrayHasKey('jobs_requeued|queue=default', $snapshot['counters']);
        $this->assertSame(1, $snapshot['counters']['jobs_failed|queue=default']);
        $this->assertSame(1, $snapshot['counters']['jobs_requeued|queue=default']);
        $this->assertSame([[ $job, true ]], $removed);
        $this->assertSame(1, $job->getAttempt());
    }
}
