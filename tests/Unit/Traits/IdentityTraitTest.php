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

namespace Tests\Unit\Traits;

use Daycry\Jobs\Job;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class IdentityTraitTest extends TestCase
{
    private function job(): Job
    {
        return new Job(job: 'command', payload: 'test');
    }

    public function testNamedSetsName(): void
    {
        $job = $this->job()->named('my_job');
        $this->assertSame('my_job', $job->getName());
    }

    public function testGetNameGeneratesDefaultFromJobAndPayload(): void
    {
        $job  = $this->job(); // no named() call
        $name = $job->getName();
        $this->assertStringStartsWith('command-', $name);
    }

    public function testSetJobIdAndGetJobId(): void
    {
        $job = $this->job()->setJobId('some-job-id');
        $this->assertSame('some-job-id', $job->getJobId());
    }

    public function testGetJobIdReturnsNullWhenNotSet(): void
    {
        $this->assertNull($this->job()->getJobId());
    }

    public function testDependsOnWithArray(): void
    {
        $job = $this->job()->dependsOn(['job1', 'job2']);
        $this->assertSame(['job1', 'job2'], $job->getDependsOn());
    }

    public function testDependsOnWithString(): void
    {
        $job = $this->job()->dependsOn('job1');
        $this->assertSame(['job1'], $job->getDependsOn());
    }

    public function testGetDependsOnReturnsEmptyArrayByDefault(): void
    {
        $this->assertSame([], $this->job()->getDependsOn());
    }
}
