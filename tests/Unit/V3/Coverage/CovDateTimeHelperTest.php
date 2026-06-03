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
use Daycry\Jobs\Libraries\DateTimeHelper;

/**
 * @internal
 */
final class CovDateTimeHelperTest extends CIUnitTestCase
{
    public function testParseImmutableReturnsSameInstanceForDateTimeImmutable(): void
    {
        $immutable = new DateTimeImmutable('2026-01-01 00:00:00');
        $this->assertSame($immutable, DateTimeHelper::parseImmutable($immutable));
    }

    public function testParseImmutableConvertsMutableDateTime(): void
    {
        $mutable = new DateTime('2026-01-01 12:00:00');
        $result  = DateTimeHelper::parseImmutable($mutable);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($mutable->getTimestamp(), $result->getTimestamp());
    }

    public function testParseImmutableFromNumericTimestamp(): void
    {
        $result = DateTimeHelper::parseImmutable(1_700_000_000);
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame(1_700_000_000, $result->getTimestamp());
    }

    public function testParseImmutableFromNumericString(): void
    {
        $result = DateTimeHelper::parseImmutable('1700000000');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame(1_700_000_000, $result->getTimestamp());
    }

    public function testParseImmutableFromParseableString(): void
    {
        $result = DateTimeHelper::parseImmutable('2026-06-03 10:00:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-06-03 10:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testParseImmutableInvalidStringReturnsNull(): void
    {
        $this->assertNull(DateTimeHelper::parseImmutable('not-a-date-!!'));
    }

    public function testParseImmutableEmptyStringReturnsNull(): void
    {
        $this->assertNull(DateTimeHelper::parseImmutable(''));
    }

    public function testParseImmutableNullReturnsNull(): void
    {
        $this->assertNull(DateTimeHelper::parseImmutable(null));
    }

    public function testParseImmutableUnsupportedTypeReturnsNull(): void
    {
        $this->assertNull(DateTimeHelper::parseImmutable(['x']));
    }

    public function testNowReturnsImmutable(): void
    {
        $this->assertInstanceOf(DateTimeImmutable::class, DateTimeHelper::now());
    }
}
