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
