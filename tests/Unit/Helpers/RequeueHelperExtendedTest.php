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

namespace Tests\Unit\Helpers;

use Daycry\Jobs\Job;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RequeueHelper;
use Tests\Support\TestCase;

/**
 * Extended RequeueHelper tests — covers permanently failed / max retries path.
 *
 * @internal
 */
final class RequeueHelperExtendedTest extends TestCase
{
    private function dummyJob(): Job
    {
        return new Job(job: 'command', payload: 'jobs:test');
    }

    public function testFinalizeFailureWithNoRetriesMarksPermFailed(): void
    {
        $job = $this->dummyJob();
        // maxRetries = null means fail immediately (no requeue)
        $envelope = JobEnvelope::fromJob($job, []);
        $metrics  = new InMemoryMetricsCollector();
        $removed  = [];
        $helper   = new RequeueHelper($metrics);
        $helper->finalize($job, $envelope, static function ($j, $r) use (&$removed): void {
            $removed[] = [$j, $r];
        }, false);

        $snapshot = $metrics->getSnapshot();
        $this->assertArrayHasKey('jobs_failed|queue=default', $snapshot['counters']);
        $this->assertArrayHasKey('jobs_failed_permanently|queue=default', $snapshot['counters']);
        // Should NOT have been requeued
        $this->assertArrayNotHasKey('jobs_requeued|queue=default', $snapshot['counters']);
        $this->assertSame([[$job, false]], $removed);
    }

    public function testFinalizeFailureExceedingMaxRetriesMarksPermFailed(): void
    {
        $job = $this->dummyJob();
        $job->maxRetries(1);
        // Simulate 2 failed attempts already (attempt > maxRetries, so no more requeue)
        $job->addAttempt(); // 1
        $job->addAttempt(); // 2 — now at maxRetries+1, so next finalize should NOT requeue
        $envelope = JobEnvelope::fromJob($job, []);
        $metrics  = new InMemoryMetricsCollector();
        $removed  = [];
        $helper   = new RequeueHelper($metrics);
        $helper->finalize($job, $envelope, static function ($j, $r) use (&$removed): void {
            $removed[] = [$j, $r];
        }, false);

        $snapshot = $metrics->getSnapshot();
        $this->assertArrayHasKey('jobs_failed_permanently|queue=default', $snapshot['counters']);
        $this->assertSame([[$job, false]], $removed);
    }

    public function testFinalizeSuccessWithoutInjectedMetrics(): void
    {
        $job      = $this->dummyJob();
        $envelope = JobEnvelope::fromJob($job, []);
        $removed  = [];
        // No metrics injected — will lazy-resolve from Metrics facade
        $helper = new RequeueHelper();
        $helper->finalize($job, $envelope, static function ($j, $r) use (&$removed): void {
            $removed[] = [$j, $r];
        }, true);

        $this->assertSame([[$job, false]], $removed);
        $this->assertSame(1, $job->getAttempt());
    }
}
