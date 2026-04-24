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

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Jobs\ClosureJob;
use Daycry\Jobs\Jobs\CommandJob;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ClosureJobAndCommandJobTest extends TestCase
{
    // ClosureJob tests

    public function testClosureJobHandleCallsCallable(): void
    {
        $handler = new ClosureJob();
        $result  = $handler->handle(static fn () => 'done');
        $this->assertSame('done', $result);
    }

    public function testClosureJobHandleThrowsOnNonCallable(): void
    {
        $this->expectException(JobException::class);
        (new ClosureJob())->handle('not-callable');
    }

    public function testClosureJobHandleThrowsOnNull(): void
    {
        $this->expectException(JobException::class);
        (new ClosureJob())->handle(null);
    }

    // CommandJob tests

    public function testCommandJobHandleThrowsOnNonString(): void
    {
        $this->expectException(JobException::class);
        (new CommandJob())->handle(123);
    }

    public function testCommandJobHandleThrowsOnEmptyString(): void
    {
        $this->expectException(JobException::class);
        (new CommandJob())->handle('');
    }

    public function testCommandJobHandleThrowsOnNull(): void
    {
        $this->expectException(JobException::class);
        (new CommandJob())->handle(null);
    }
}
