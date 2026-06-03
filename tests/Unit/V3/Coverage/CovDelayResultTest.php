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
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Daycry\Jobs\Libraries\DelayResult;

/**
 * @internal
 */
final class CovDelayResultTest extends CIUnitTestCase
{
    public function testFromScheduleNullIsImmediate(): void
    {
        $result = DelayResult::fromSchedule(null);
        $this->assertSame(0, $result->seconds);
        $this->assertTrue($result->isImmediate());
        $this->assertNotInstanceOf(DateTimeInterface::class, $result->scheduledAt);
    }

    public function testFromSchedulePastDateTimeClampsToZero(): void
    {
        $past   = new DateTime('-1 hour');
        $result = DelayResult::fromSchedule($past);

        $this->assertSame(0, $result->seconds);
        $this->assertTrue($result->isImmediate());
        $this->assertSame($past, $result->scheduledAt);
    }

    public function testFromScheduleFutureDateTimeImmutableHasPositiveDelay(): void
    {
        $future = new DateTimeImmutable('+120 seconds');
        $result = DelayResult::fromSchedule($future);

        $this->assertGreaterThan(0, $result->seconds);
        $this->assertLessThanOrEqual(120, $result->seconds);
        $this->assertFalse($result->isImmediate());
        $this->assertSame($future, $result->scheduledAt);
    }

    public function testConstructorDirectValues(): void
    {
        $when   = new DateTimeImmutable('+30 seconds');
        $result = new DelayResult(30, $when);

        $this->assertSame(30, $result->seconds);
        $this->assertSame($when, $result->scheduledAt);
        $this->assertFalse($result->isImmediate());
    }
}
