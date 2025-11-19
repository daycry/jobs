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

use Daycry\Jobs\Libraries\DateTimeHelper;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DateTimeHelperTest extends TestCase
{
    public function testParseImmutableFromString(): void
    {
        $dt = DateTimeHelper::parseImmutable('2025-01-02 03:04:05');
        $this->assertNotNull($dt);
        $this->assertSame('2025-01-02 03:04:05', $dt->format('Y-m-d H:i:s'));
    }

    public function testParseImmutableFromTimestamp(): void
    {
        $ts = 1700000000;
        $dt = DateTimeHelper::parseImmutable($ts);
        $this->assertNotNull($dt);
        $this->assertSame($ts, $dt->getTimestamp());
    }

    public function testParseImmutableInvalid(): void
    {
        $this->assertNull(DateTimeHelper::parseImmutable('')); // empty string
        $this->assertNull(DateTimeHelper::parseImmutable('not-a-date'));
        $this->assertNull(DateTimeHelper::parseImmutable(null));
    }

    public function testNow(): void
    {
        $now = DateTimeHelper::now();
        $this->assertInstanceOf(DateTimeImmutable::class, $now);
    }
}
