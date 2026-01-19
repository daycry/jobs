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
final class StateTraitTest extends TestCase
{
    public function testNotifyOnSuccessSetsFlag(): void
    {
        $job = new Job('command', 'test');

        $result = $job->notifyOnSuccess();

        $this->assertSame($job, $result);
        $this->assertTrue($job->shouldNotifyOnSuccess());
    }

    public function testNotifyOnFailureSetsFlag(): void
    {
        $job = new Job('command', 'test');

        $result = $job->notifyOnFailure();

        $this->assertSame($job, $result);
        $this->assertTrue($job->shouldNotifyOnFailure());
    }

    public function testNotifyOnCompletionSetsBothFlags(): void
    {
        $job = new Job('command', 'test');

        $result = $job->notifyOnCompletion();

        $this->assertSame($job, $result);
        $this->assertTrue($job->shouldNotifyOnSuccess());
        $this->assertTrue($job->shouldNotifyOnFailure());
    }

    public function testSingleInstanceSetsFlag(): void
    {
        $job = new Job('command', 'test');

        $result = $job->singleInstance();

        $this->assertSame($job, $result);
        $this->assertTrue($job->isSingleInstance());
    }

    public function testIsSingleInstanceReturnsFalseByDefault(): void
    {
        $job = new Job('command', 'test');

        $this->assertFalse($job->isSingleInstance());
    }

    public function testShouldNotifyOnSuccessReturnsFalseByDefault(): void
    {
        $job = new Job('command', 'test');

        $this->assertFalse($job->shouldNotifyOnSuccess());
    }

    public function testShouldNotifyOnFailureReturnsFalseByDefault(): void
    {
        $job = new Job('command', 'test');

        $this->assertFalse($job->shouldNotifyOnFailure());
    }

    public function testDisablePreventsExecution(): void
    {
        $job = new Job('command', 'test');

        $result = $job->disable();

        $this->assertSame($job, $result);
        $this->assertFalse($job->isEnabled());
    }

    public function testIsEnabledReturnsTrueByDefault(): void
    {
        $job = new Job('command', 'test');

        $this->assertTrue($job->isEnabled());
    }

    public function testIsRunningReturnsFalseByDefault(): void
    {
        $job = new Job('command', 'test');

        $this->assertFalse($job->isRunning());
    }
}
