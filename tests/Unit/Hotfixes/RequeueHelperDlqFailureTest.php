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

namespace Tests\Unit\Hotfixes;

use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\ConfigCache;
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RequeueHelper;
use Tests\Support\TestCase;

/**
 * Hotfix F2/F3/F4: validates that RequeueHelper coordinates with the DLQ
 * fail-loud return value and emits jobs_dlq_failed when the DLQ is unavailable.
 *
 * @internal
 */
final class RequeueHelperDlqFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConfigCache::clear();
        $this->jobsConfig()->deadLetterQueue = null;
        ConfigCache::set($this->jobsConfig());
    }

    protected function tearDown(): void
    {
        $this->jobsConfig()->deadLetterQueue = null;
        ConfigCache::clear();
        parent::tearDown();
    }

    private function dummyJob(): Job
    {
        return new Job(job: 'command', payload: 'jobs:test');
    }

    public function testPermanentFailureWithDlqDisabledEmitsDlqFailedMetric(): void
    {
        $this->jobsConfig()->deadLetterQueue = null;
        ConfigCache::set($this->jobsConfig());

        $job      = $this->dummyJob(); // maxRetries default = null → no retries
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
        $this->assertArrayHasKey(
            'jobs_dlq_failed|queue=default',
            $snapshot['counters'],
            'jobs_dlq_failed must be emitted when DLQ store() returns false (here because DLQ disabled).',
        );
        $this->assertSame(1, $snapshot['counters']['jobs_dlq_failed|queue=default']);

        // The job is still removed from origin (single removeFn call with recreate=false).
        $this->assertSame([[$job, false]], $removed);
    }

    public function testPermanentFailureWithDlqEnabledDoesNotEmitDlqFailed(): void
    {
        // Enable DLQ pointing to one of the configured queues so push() succeeds.
        $this->jobsConfig()->deadLetterQueue = 'default';
        ConfigCache::set($this->jobsConfig());

        $job = $this->dummyJob();
        $job->setQueue('default'); // ensures DLQ clone has a valid origin queue
        $envelope = JobEnvelope::fromJob($job, []);
        $metrics  = new InMemoryMetricsCollector();
        $removed  = [];
        $helper   = new RequeueHelper($metrics);

        $helper->finalize($job, $envelope, static function ($j, $r) use (&$removed): void {
            $removed[] = [$j, $r];
        }, false);

        $snapshot = $metrics->getSnapshot();

        $this->assertArrayHasKey('jobs_failed_permanently|queue=default', $snapshot['counters']);
        $this->assertArrayNotHasKey(
            'jobs_dlq_failed|queue=default',
            $snapshot['counters'],
            'jobs_dlq_failed must NOT be emitted when DLQ stored the job successfully.',
        );
    }

    public function testAttemptCounterIsIncrementedExactlyOncePerCycle(): void
    {
        $job = $this->dummyJob();
        $job->maxRetries(2);
        $this->assertSame(0, $job->getAttempt());

        $envelope = JobEnvelope::fromJob($job, []);
        $metrics  = new InMemoryMetricsCollector();
        $helper   = new RequeueHelper($metrics);

        $helper->finalize($job, $envelope, static fn ($j, $r) => null, false);
        $this->assertSame(1, $job->getAttempt(), 'First failure cycle should leave attempts at 1.');

        $helper->finalize($job, $envelope, static fn ($j, $r) => null, false);
        $this->assertSame(2, $job->getAttempt(), 'Second failure cycle should leave attempts at 2.');

        // Third failure: nextAttempt=3, 3 < (2+1)=3 is false → permanent failure.
        // Counter should still increment to 3 (one per cycle).
        $helper->finalize($job, $envelope, static fn ($j, $r) => null, false);
        $this->assertSame(3, $job->getAttempt());
    }
}
