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

namespace Tests\Unit\Exceptions;

use Daycry\Jobs\Exceptions\JobException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JobExceptionTest extends TestCase
{
    public function testForInvalidJob(): void
    {
        $e = JobException::forInvalidJob('MyJob');
        $this->assertStringContainsString('MyJob', $e->getMessage());
    }

    public function testTaskAlreadyRunning(): void
    {
        $e = JobException::TaskAlreadyRunningException('MyJob');
        $this->assertStringContainsString('MyJob', $e->getMessage());
    }

    public function testForInvalidLogType(): void
    {
        $e = JobException::forInvalidLogType();
        $this->assertNotEmpty($e->getMessage());
    }

    public function testValidationError(): void
    {
        $e = JobException::validationError('Some validation error');
        $this->assertStringContainsString('Some validation error', $e->getMessage());
    }

    public function testForInvalidMethod(): void
    {
        $e = JobException::forInvalidMethod('PATCH');
        $this->assertStringContainsString('PATCH', $e->getMessage());
    }

    public function testForInvalidPriority(): void
    {
        $e = JobException::forInvalidPriority(99);
        $this->assertStringContainsString('99', $e->getMessage());
    }

    public function testForShellCommandNotAllowed(): void
    {
        $e = JobException::forShellCommandNotAllowed('rm -rf /');
        $this->assertStringContainsString('rm -rf /', $e->getMessage());
    }

    public function testForJobTimeout(): void
    {
        $e = JobException::forJobTimeout('LongJob', 120);
        $this->assertStringContainsString('LongJob', $e->getMessage());
        $this->assertStringContainsString('120', $e->getMessage());
    }

    public function testForRateLimitExceeded(): void
    {
        $e = JobException::forRateLimitExceeded('default', 100);
        $this->assertStringContainsString('default', $e->getMessage());
        $this->assertStringContainsString('100', $e->getMessage());
    }
}
