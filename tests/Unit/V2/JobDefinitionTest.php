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

namespace Tests\Unit\V2;

use DateTimeImmutable;
use Daycry\Jobs\Job;
use Daycry\Jobs\V2\JobDefinition;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class JobDefinitionTest extends TestCase
{
    public function testConstructAssignsAllFields(): void
    {
        $when = new DateTimeImmutable('2026-06-01 12:00:00');

        $def = new JobDefinition(
            handler: 'command',
            payload: 'jobs:test',
            name: 'demo',
            queue: 'high',
            priority: 9,
            maxRetries: 3,
            timeout: 60,
            scheduledAt: $when,
            singleInstance: true,
            environments: ['production'],
            dependsOn: ['previous-job'],
            cronExpression: '0 * * * *',
            meta: ['origin' => 'unit-test'],
        );

        $this->assertSame('command', $def->handler);
        $this->assertSame('jobs:test', $def->payload);
        $this->assertSame('demo', $def->name);
        $this->assertSame('high', $def->queue);
        $this->assertSame(9, $def->priority);
        $this->assertSame(3, $def->maxRetries);
        $this->assertSame(60, $def->timeout);
        $this->assertSame($when, $def->scheduledAt);
        $this->assertTrue($def->singleInstance);
        $this->assertSame(['production'], $def->environments);
        $this->assertSame(['previous-job'], $def->dependsOn);
        $this->assertSame('0 * * * *', $def->cronExpression);
        $this->assertSame(['origin' => 'unit-test'], $def->meta);
    }

    public function testWithMethodsReturnNewInstanceWithoutMutatingOriginal(): void
    {
        $original = new JobDefinition(handler: 'closure', payload: 42);
        $renamed  = $original->withName('x')->withQueue('q')->withPriority(8);

        $this->assertNull($original->name);
        $this->assertNull($original->queue);
        $this->assertSame(5, $original->priority);

        $this->assertSame('x', $renamed->name);
        $this->assertSame('q', $renamed->queue);
        $this->assertSame(8, $renamed->priority);
    }

    public function testWithScheduledAtAcceptsNullToClear(): void
    {
        $def     = (new JobDefinition('command', 'p'))->withScheduledAt(new DateTimeImmutable('+1 hour'));
        $cleared = $def->withScheduledAt(null);

        $this->assertNotNull($def->scheduledAt);
        $this->assertNull($cleared->scheduledAt);
    }

    public function testFromLegacyJobCopiesCoreFields(): void
    {
        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('legacy_demo')
            ->setQueue('reports')
            ->maxRetries(4)
            ->timeout(45)
            ->singleInstance()
            ->dependsOn(['report-data']);

        $def = JobDefinition::fromLegacyJob($job);

        $this->assertSame('command', $def->handler);
        $this->assertSame('jobs:test', $def->payload);
        $this->assertSame('legacy_demo', $def->name);
        $this->assertSame('reports', $def->queue);
        $this->assertSame(4, $def->maxRetries);
        $this->assertSame(45, $def->timeout);
        $this->assertTrue($def->singleInstance);
        $this->assertSame(['report-data'], $def->dependsOn);
    }
}
