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

use Daycry\Jobs\Job;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RequeueHelper;
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
        $helper->finalize($job, $envelope, static function ($j, $r) use (&$removed): void {
            $removed[] = [$j, $r];
        }, true);

        $snapshot = $metrics->getSnapshot();
        $this->assertArrayHasKey('jobs_succeeded|queue=default', $snapshot['counters']);
        $this->assertSame(1, $snapshot['counters']['jobs_succeeded|queue=default']);
        $this->assertSame([[$job, false]], $removed);
    }

    public function testFinalizeFailureRequeues(): void
    {
        $job      = $this->dummyJob();
        $envelope = JobEnvelope::fromJob($job, []);
        $metrics  = new InMemoryMetricsCollector();
        $removed  = [];
        $helper   = new RequeueHelper($metrics);
        $helper->finalize($job, $envelope, static function ($j, $r) use (&$removed): void {
            $removed[] = [$j, $r];
        }, false);

        $snapshot = $metrics->getSnapshot();
        $this->assertArrayHasKey('jobs_failed|queue=default', $snapshot['counters']);
        $this->assertArrayHasKey('jobs_requeued|queue=default', $snapshot['counters']);
        $this->assertSame(1, $snapshot['counters']['jobs_failed|queue=default']);
        $this->assertSame(1, $snapshot['counters']['jobs_requeued|queue=default']);
        $this->assertSame([[$job, true]], $removed);
        $this->assertSame(1, $job->getAttempt());
    }
}
