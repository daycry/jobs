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
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\Timeout;
use RuntimeException;

/**
 * @internal
 */
final class CovTimeoutTest extends CIUnitTestCase
{
    public function testZeroSecondsRunsImmediatelyWithoutGuard(): void
    {
        $result = (new Timeout())->run(0, static fn (): string => 'immediate');
        $this->assertSame('immediate', $result);
    }

    public function testNegativeSecondsRunsImmediatelyWithoutGuard(): void
    {
        $result = (new Timeout())->run(-5, static fn (): int => 99);
        $this->assertSame(99, $result);
    }

    public function testReturnsValueWhenWithinDeadline(): void
    {
        $result = (new Timeout())->run(5, static fn (): string => 'fast');
        $this->assertSame('fast', $result);
    }

    public function testPropagatesCallbackException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handler-failed');
        (new Timeout())->run(5, static function (): void {
            throw new RuntimeException('handler-failed');
        });
    }

    public function testThrowsJobExceptionWhenDeadlineExceeded(): void
    {
        // On the soft-fallback path (no pcntl) the callback completes but the elapsed time
        // exceeds the deadline; under pcntl the SIGALRM handler interrupts the usleep.
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('exceeded maximum execution time');
        (new Timeout())->run(1, static function (): string {
            usleep(1_100_000);

            return 'too-slow';
        }, 'cov-slow-job');
    }
}
