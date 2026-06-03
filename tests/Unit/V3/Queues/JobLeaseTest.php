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

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;

/**
 * @internal
 */
final class JobLeaseTest extends CIUnitTestCase
{
    private function envelope(): JobEnvelope
    {
        return new JobEnvelope(id: 'id-1', queue: 'default', payload: (object) ['job' => 'command']);
    }

    public function testWithRelativeExpiryCarriesOwnerToken(): void
    {
        $lease = JobLease::withRelativeExpiry($this->envelope(), 'tok', 'owner-abc', 300, 'database');
        $this->assertSame('tok', $lease->token);
        $this->assertSame('owner-abc', $lease->ownerToken);
        $this->assertSame('database', $lease->backend);
        $this->assertFalse($lease->isExpired());
    }

    public function testIsExpiredInPast(): void
    {
        $past  = new DateTimeImmutable('2000-01-01 00:00:00');
        $lease = new JobLease($this->envelope(), 'tok', 'owner', $past, 'redis');
        $this->assertTrue($lease->isExpired());
    }

    public function testRenewExtendsExpiryPreservingTokens(): void
    {
        $lease   = JobLease::withRelativeExpiry($this->envelope(), 'tok', 'owner', 1, 'redis');
        $renewed = $lease->renew(300);
        $this->assertGreaterThan($lease->expiresAt, $renewed->expiresAt);
        $this->assertSame($lease->token, $renewed->token);
        $this->assertSame($lease->ownerToken, $renewed->ownerToken);
        $this->assertSame($lease->backend, $renewed->backend);
    }
}
