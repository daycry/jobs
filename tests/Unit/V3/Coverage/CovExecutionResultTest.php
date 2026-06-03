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
use Daycry\Jobs\Execution\ExecutionResult;

/**
 * @internal
 */
final class CovExecutionResultTest extends CIUnitTestCase
{
    public function testDurationSecondsPositiveSpan(): void
    {
        $result = new ExecutionResult(true, 'ok', null, 100.0, 102.5, self::class);
        $this->assertEqualsWithDelta(2.5, $result->durationSeconds(), PHP_FLOAT_EPSILON);
        $this->assertTrue($result->success);
        $this->assertSame('ok', $result->output);
        $this->assertNull($result->error);
        $this->assertSame(self::class, $result->handlerClass);
    }

    public function testDurationSecondsClampedToZeroWhenEndedBeforeStart(): void
    {
        $result = new ExecutionResult(false, null, 'boom', 200.0, 150.0);
        $this->assertEqualsWithDelta(0.0, $result->durationSeconds(), PHP_FLOAT_EPSILON);
        $this->assertFalse($result->success);
        $this->assertSame('boom', $result->error);
        $this->assertNull($result->handlerClass);
    }

    public function testDurationSecondsZeroSpan(): void
    {
        $result = new ExecutionResult(true, '', null, 50.0, 50.0);
        $this->assertEqualsWithDelta(0.0, $result->durationSeconds(), PHP_FLOAT_EPSILON);
    }
}
