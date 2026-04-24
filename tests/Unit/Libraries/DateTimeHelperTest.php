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

namespace Tests\Unit\Libraries;

use DateTime;
use DateTimeImmutable;
use Daycry\Jobs\Libraries\DateTimeHelper;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DateTimeHelperTest extends TestCase
{
    public function testParseImmutableFromDateTimeImmutable(): void
    {
        $input  = new DateTimeImmutable('2024-01-01 12:00:00');
        $result = DateTimeHelper::parseImmutable($input);
        $this->assertSame($input, $result);
    }

    public function testParseImmutableFromDateTimeInterface(): void
    {
        $input  = new DateTime('2024-06-15 09:30:00');
        $result = DateTimeHelper::parseImmutable($input);
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($input->getTimestamp(), $result->getTimestamp());
    }

    public function testParseImmutableFromNumericTimestamp(): void
    {
        $ts     = strtotime('2024-01-01 00:00:00');
        $result = DateTimeHelper::parseImmutable($ts);
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($ts, $result->getTimestamp());
    }

    public function testParseImmutableFromStringDate(): void
    {
        $result = DateTimeHelper::parseImmutable('2024-03-20 15:00:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
    }

    public function testParseImmutableFromNullReturnsNull(): void
    {
        $this->assertNotInstanceOf(DateTimeImmutable::class, DateTimeHelper::parseImmutable(null));
    }

    public function testParseImmutableFromEmptyStringReturnsNull(): void
    {
        $this->assertNotInstanceOf(DateTimeImmutable::class, DateTimeHelper::parseImmutable(''));
    }

    public function testParseImmutableFromInvalidStringReturnsNull(): void
    {
        $this->assertNotInstanceOf(DateTimeImmutable::class, DateTimeHelper::parseImmutable('not-a-date-xyz'));
    }

    public function testNowReturnsDateTimeImmutable(): void
    {
        $result = DateTimeHelper::now();
        $this->assertGreaterThan(0, $result->getTimestamp());
    }

    public function testNowIsApproximatelyCurrentTime(): void
    {
        $before = time();
        $result = DateTimeHelper::now();
        $after  = time();
        $this->assertGreaterThanOrEqual($before, $result->getTimestamp());
        $this->assertLessThanOrEqual($after, $result->getTimestamp());
    }
}
