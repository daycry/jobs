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

namespace Tests\Unit\Jobs;

use Daycry\Jobs\Job;
use PHPUnit\Framework\TestCase;

/**
 * Full coverage of CallbackTrait via Job.
 *
 * @internal
 */
final class CallbackTraitTest extends TestCase
{
    private function job(): Job
    {
        return new Job(job: 'command', payload: 'jobs:test');
    }

    public function testSetCallbackJobSetsDescriptor(): void
    {
        $parent   = $this->job();
        $callback = static fn ($j) => $j;
        $parent->setCallbackJob($callback);
        $this->assertTrue($parent->hasCallbackJob());
        $descriptor = $parent->getCallbackDescriptor();
        $this->assertNotNull($descriptor);
        $this->assertSame('always', $descriptor->filter);
    }

    public function testSetCallbackJobWithFilterSuccess(): void
    {
        $parent = $this->job();
        $parent->setCallbackJob(static fn ($j) => $j, ['filter' => 'success']);
        $this->assertSame('success', $parent->getCallbackDescriptor()->filter);
    }

    public function testSetCallbackJobWithFilterFailure(): void
    {
        $parent = $this->job();
        $parent->setCallbackJob(static fn ($j) => $j, ['filter' => 'failure']);
        $this->assertSame('failure', $parent->getCallbackDescriptor()->filter);
    }

    public function testSetCallbackJobWithFilterErrorNormalizesToFailure(): void
    {
        $parent = $this->job();
        $parent->setCallbackJob(static fn ($j) => $j, ['filter' => 'error']);
        $this->assertSame('failure', $parent->getCallbackDescriptor()->filter);
    }

    public function testSetCallbackJobWithOnShorthand(): void
    {
        $parent = $this->job();
        $parent->setCallbackJob(static fn ($j) => $j, ['on' => 'success']);
        $this->assertSame('success', $parent->getCallbackDescriptor()->filter);
    }

    public function testSetCallbackJobInheritDefaults(): void
    {
        $parent = $this->job();
        $parent->setCallbackJob(static fn ($j) => $j);
        $this->assertSame(['output', 'error'], $parent->getCallbackDescriptor()->inherit);
    }

    public function testSetCallbackJobAllowChain(): void
    {
        $parent = $this->job();
        $parent->setCallbackJob(static fn ($j) => $j, ['allowChain' => true]);
        $this->assertTrue($parent->getCallbackDescriptor()->allowChain);
    }

    public function testThenSetsSuccessCallback(): void
    {
        $parent = $this->job();
        $child  = $this->job();
        $parent->then($child);
        $descriptor = $parent->getCallbackDescriptor();
        $this->assertNotNull($descriptor);
        $this->assertSame('success', $descriptor->filter);
        $this->assertTrue($descriptor->allowChain);
        // The builder should return the child job
        $builtJob = ($descriptor->builder)($parent);
        $this->assertSame($child, $builtJob);
    }

    public function testCatchSetsFailureCallback(): void
    {
        $parent  = $this->job();
        $failure = $this->job();
        $parent->catch($failure);
        $descriptor = $parent->getCallbackDescriptor();
        $this->assertSame('failure', $descriptor->filter);
        $this->assertFalse($descriptor->allowChain);
    }

    public function testFinallyAlwaysCallback(): void
    {
        $parent  = $this->job();
        $finally = $this->job();
        $parent->finally($finally);
        $descriptor = $parent->getCallbackDescriptor();
        $this->assertSame('always', $descriptor->filter);
    }

    public function testHasCallbackJobReturnsFalseWhenNoneSet(): void
    {
        $parent = $this->job();
        $this->assertFalse($parent->hasCallbackJob());
    }

    public function testGetCallbackDescriptorReturnsNullWhenNoneSet(): void
    {
        $parent = $this->job();
        $this->assertNull($parent->getCallbackDescriptor());
    }

    public function testChainBuildsLinkedCallbacks(): void
    {
        $parent = $this->job();
        $job1   = $this->job()->named('job1');
        $job2   = $this->job()->named('job2');
        $parent->chain([$job1, $job2]);
        // Parent's callback should be job1
        $descriptor = $parent->getCallbackDescriptor();
        $this->assertNotNull($descriptor);
        $builtJob = ($descriptor->builder)($parent);
        $this->assertSame($job1, $builtJob);
        // job1's callback should be job2
        $descriptor2 = $job1->getCallbackDescriptor();
        $this->assertNotNull($descriptor2);
        $builtJob2 = ($descriptor2->builder)($job1);
        $this->assertSame($job2, $builtJob2);
    }

    public function testChainWithEmptyArrayDoesNothing(): void
    {
        $parent = $this->job();
        $parent->chain([]);
        $this->assertFalse($parent->hasCallbackJob());
    }
}
