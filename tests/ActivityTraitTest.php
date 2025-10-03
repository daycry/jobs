<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use PHPUnit\Framework\TestCase;

/** @internal */
final class ActivityTraitTest extends TestCase
{
    public function testMaxRetriesAndTimeoutAccessors(): void
    {
        $job = new Job(job: 'demo', payload: 'x');
        // By default traits may provide defaults; exercise fluent API if available
        $this->assertNull($job->getMaxRetries());
        $this->assertNull($job->getTimeout());
        $job->maxRetries(7)->timeout(123);
        $this->assertSame(7, $job->getMaxRetries());
        $this->assertSame(123, $job->getTimeout());
    }
}
