<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;

/**
 * @internal
 */
final class JobExceptionFactoriesTest extends CIUnitTestCase
{
    public function testShellCommandsNotConfigured(): void
    {
        $e = JobException::forShellCommandsNotConfigured();
        $this->assertStringContainsString('deny-by-default', $e->getMessage());
    }

    public function testEventNotAllowed(): void
    {
        $e = JobException::forEventNotAllowed('user.deleted');
        $this->assertStringContainsString('user.deleted', $e->getMessage());
    }
}
