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
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\V2\Queues\JobLease;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class JobLeaseTest extends TestCase
{
    private function envelope(): JobEnvelope
    {
        return new JobEnvelope(
            id: 'demo-1',
            queue: 'default',
            payload: (object) ['foo' => 'bar'],
            attempts: 0,
            priority: null,
            scheduledAt: null,
            availableAt: null,
            createdAt: new DateTimeImmutable(),
            meta: [],
            raw: (object) [],
        );
    }

    public function testRelativeExpiryComputesFutureDeadline(): void
    {
        $lease = JobLease::withRelativeExpiry($this->envelope(), 'tok', 60, 'redis');

        $this->assertSame('tok', $lease->token);
        $this->assertSame('redis', $lease->backend);
        $this->assertGreaterThan(new DateTimeImmutable(), $lease->expiresAt);
        $this->assertFalse($lease->isExpired());
    }

    public function testIsExpiredAgainstSuppliedClock(): void
    {
        $lease = new JobLease(
            envelope: $this->envelope(),
            token: 'tok',
            expiresAt: new DateTimeImmutable('2025-01-01 00:00:00'),
            backend: 'database',
        );

        $this->assertTrue($lease->isExpired(new DateTimeImmutable('2026-01-01 00:00:00')));
        $this->assertFalse($lease->isExpired(new DateTimeImmutable('2024-01-01 00:00:00')));
    }
}
