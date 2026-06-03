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
use Daycry\Jobs\Libraries\Priority;

/**
 * @internal
 */
final class CovPriorityTest extends CIUnitTestCase
{
    public function testParseNullReturnsNull(): void
    {
        $this->assertNull(Priority::parse(null));
    }

    public function testParseEnumInstanceReturnsItself(): void
    {
        $this->assertSame(Priority::HIGH, Priority::parse(Priority::HIGH));
    }

    public function testParseSymbolicStringsCaseInsensitive(): void
    {
        $this->assertSame(Priority::LOW, Priority::parse('low'));
        $this->assertSame(Priority::MEDIUM, Priority::parse('Medium'));
        $this->assertSame(Priority::HIGH, Priority::parse('HIGH'));
    }

    public function testParseUnknownStringReturnsNull(): void
    {
        $this->assertNull(Priority::parse('urgent'));
    }

    public function testParseIntKnownValue(): void
    {
        $this->assertSame(Priority::LOW, Priority::parse(10));
        $this->assertSame(Priority::MEDIUM, Priority::parse(5));
        $this->assertSame(Priority::HIGH, Priority::parse(1));
    }

    public function testParseIntUnknownValueReturnsNull(): void
    {
        $this->assertNull(Priority::parse(42));
    }

    public function testParseUnsupportedTypeReturnsNull(): void
    {
        $this->assertNull(Priority::parse(3.14));
        $this->assertNull(Priority::parse(['x']));
    }

    public function testToNumericReturnsBackingValue(): void
    {
        $this->assertSame(10, Priority::LOW->toNumeric());
        $this->assertSame(5, Priority::MEDIUM->toNumeric());
        $this->assertSame(1, Priority::HIGH->toNumeric());
    }
}
