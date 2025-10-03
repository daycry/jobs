<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\JobEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JobEnvelopeTest extends TestCase
{
    public function testFromJobPopulatesFields(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $env = JobEnvelope::fromJob($job, ['x' => 1]);
        $this->assertSame('default', $env->queue);
        $this->assertNotEmpty($env->id);
        $this->assertSame($job->getAttempt(), $env->attempts);
        $this->assertArrayHasKey('x', $env->meta);
    // createdAt may be null if job has no schedule; ensure property exists (no exception) and type is either DateTimeInterface or null
    $this->assertTrue($env->createdAt === null || $env->createdAt instanceof DateTimeInterface);
    }

    public function testFromDecoded(): void
    {
        $env = JobEnvelope::fromDecoded('abc', 'q', (object)['foo' => 'bar'], 2, priority: 5, createdAt: null, meta: ['k' => 'v']);
        $this->assertSame('abc', $env->id);
        $this->assertSame('q', $env->queue);
        $this->assertSame(2, $env->attempts);
        $this->assertSame(5, $env->priority);
        $this->assertEquals('bar', $env->payload->foo ?? null);
        $this->assertSame('v', $env->meta['k']);
    }
}
