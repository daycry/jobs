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

namespace Tests\Unit\V3\Execution;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\Timeout;
use RuntimeException;

/**
 * @internal
 */
final class TimeoutTest extends CIUnitTestCase
{
    public function testZeroTimeoutBypasses(): void
    {
        $result = (new Timeout())->run(0, static fn (): string => 'x');
        $this->assertSame('x', $result);
    }

    public function testReturnsValueWithinTimeout(): void
    {
        $result = (new Timeout())->run(5, static fn (): string => 'x');
        $this->assertSame('x', $result);
    }

    public function testPropagatesCallbackException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        (new Timeout())->run(5, static function (): void {
            throw new RuntimeException('boom');
        });
    }

    public function testThrowsWhenDeadlineExceeded(): void
    {
        // Works on both the pcntl (SIGALRM interrupts the usleep) and the soft fallback paths.
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('exceeded maximum execution time');
        (new Timeout())->run(1, static function (): string {
            usleep(1_100_000);

            return 'late';
        }, 'slow-job');
    }
}
