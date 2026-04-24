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
 * Full coverage of EnvironmentTrait.
 *
 * @internal
 */
final class EnvironmentTraitTest extends TestCase
{
    public function testEnvironmentsFluentSetter(): void
    {
        $job    = new Job(job: 'command', payload: 'jobs:test');
        $result = $job->environments('production', 'staging');
        $this->assertSame($job, $result);
        $this->assertSame(['production', 'staging'], $job->getEnvironments());
    }

    public function testGetEnvironmentsDefault(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $this->assertSame([], $job->getEnvironments());
    }

    public function testInEnvironmentReturnsTrue(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->environments('production', 'staging');
        $this->assertTrue($job->inEnvironment('production'));
        $this->assertTrue($job->inEnvironment('staging'));
    }

    public function testInEnvironmentReturnsFalse(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->environments('production');
        $this->assertFalse($job->inEnvironment('testing'));
    }

    public function testInEnvironmentReturnsFalseWhenNoneSet(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $this->assertFalse($job->inEnvironment('production'));
    }
}
