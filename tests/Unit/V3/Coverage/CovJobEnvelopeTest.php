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

namespace Tests\Unit\V3\Coverage;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Daycry\Jobs\Queues\JobEnvelope;
use stdClass;

/**
 * @internal
 */
final class CovJobEnvelopeTest extends CIUnitTestCase
{
    public function testConstructorWithNamedArgs(): void
    {
        $created = new DateTimeImmutable('2026-01-01 00:00:00');
        $env     = new JobEnvelope(
            id: 'id-1',
            queue: 'default',
            payload: ['k' => 'v'],
            name: 'my-job',
            attempts: 3,
            priority: 5,
            createdAt: $created,
            meta: ['backend' => 'sync'],
            raw: 'native',
        );

        $this->assertSame('id-1', $env->id);
        $this->assertSame('default', $env->queue);
        $this->assertSame('my-job', $env->name);
        $this->assertSame(3, $env->attempts);
        $this->assertSame(5, $env->priority);
        $this->assertSame($created, $env->createdAt);
        $this->assertSame(['backend' => 'sync'], $env->meta);
        $this->assertSame('native', $env->raw);
    }

    public function testFromDecodedWithStringName(): void
    {
        // Intentionally exercising the deprecated legacy factory to cover its branches.
        // @phpstan-ignore staticMethod.deprecated
        $env = JobEnvelope::fromDecoded('id-2', 'q', ['p' => 1], 'named-job', 2);

        $this->assertSame('named-job', $env->name);
        $this->assertSame(2, $env->attempts);
    }

    public function testFromDecodedWithLegacyIntNameMapsToAttempts(): void
    {
        // Legacy positional signature: 4th arg was attempts (int), name stays null.
        // @phpstan-ignore staticMethod.deprecated
        $env = JobEnvelope::fromDecoded('id-3', 'q', ['p' => 1], 7);

        $this->assertNull($env->name);
        $this->assertSame(7, $env->attempts);
    }

    public function testFromDecodedWithNonStringNonIntNameBecomesNull(): void
    {
        // A float (not string, not int) cannot be a name nor legacy attempts -> name null.
        // @phpstan-ignore staticMethod.deprecated
        $env = JobEnvelope::fromDecoded('id-4', 'q', null, 3.5, 4);

        $this->assertNull($env->name);
        $this->assertSame(4, $env->attempts);
    }

    public function testFromBackendWithArrayPayloadExtractsFields(): void
    {
        $env = JobEnvelope::fromBackend(
            'redis',
            'rid-1',
            'emails',
            [
                'name'      => 'send-email',
                'attempts'  => 4,
                'priority'  => 1,
                'schedule'  => '2026-06-03 09:00:00',
                'createdAt' => '2026-06-01 08:00:00',
            ],
            ['status' => 'pending'],
            'rawRecord',
        );

        $this->assertSame('send-email', $env->name);
        $this->assertSame(4, $env->attempts);
        $this->assertSame(1, $env->priority);
        $this->assertInstanceOf(DateTimeInterface::class, $env->scheduledAt);
        $this->assertInstanceOf(DateTimeInterface::class, $env->createdAt);
        $this->assertSame('redis', $env->meta['backend']);
        $this->assertSame('rid-1', $env->meta['rawId']);
        $this->assertSame('pending', $env->meta['status']);
        $this->assertSame('rawRecord', $env->raw);
    }

    public function testFromBackendWithStdClassPayloadAndDefaults(): void
    {
        $payload           = new stdClass();
        $payload->name     = 'object-job';
        $payload->attempts = 2;
        // No priority, no schedule, no createdAt -> createdAt falls back to now().

        $env = JobEnvelope::fromBackend('database', 'dbid-1', 'q', $payload);

        $this->assertSame('object-job', $env->name);
        $this->assertSame(2, $env->attempts);
        $this->assertNull($env->priority);
        $this->assertNotInstanceOf(DateTimeInterface::class, $env->scheduledAt);
        $this->assertInstanceOf(DateTimeInterface::class, $env->createdAt);
        $this->assertSame('database', $env->meta['backend']);
        $this->assertSame('dbid-1', $env->meta['rawId']);
    }

    public function testFromBackendWithNullPayloadUsesDefaults(): void
    {
        $env = JobEnvelope::fromBackend('sync', 'sid-1', 'q', null);

        $this->assertNull($env->name);
        $this->assertSame(0, $env->attempts);
        $this->assertNull($env->priority);
        $this->assertNotInstanceOf(DateTimeInterface::class, $env->scheduledAt);
        $this->assertInstanceOf(DateTimeInterface::class, $env->createdAt);
        $this->assertNull($env->payload);
    }
}
