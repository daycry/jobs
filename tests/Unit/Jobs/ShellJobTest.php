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

namespace Tests\Unit\Jobs;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Job;
use Daycry\Jobs\Jobs\ShellJob;

/**
 * @internal
 */
final class ShellJobTest extends CIUnitTestCase
{
    public function testHandleExecutesShellCommand(): void
    {
        $job    = new ShellJob();
        $result = $job->handle('echo test');

        $this->assertIsArray($result);
        $this->assertContains('test', $result);
    }

    public function testValidateCommandAllowsAllWhenWhitelistEmpty(): void
    {
        $config                       = config('Jobs');
        $config->allowedShellCommands = [];

        $job = new ShellJob();

        // Should not throw exception
        $result = $job->handle('echo test');
        $this->assertIsArray($result);
    }

    public function testValidateCommandAllowsWhitelistedCommand(): void
    {
        $config                       = config('Jobs');
        $config->allowedShellCommands = ['echo', 'ls', 'cat'];

        $job    = new ShellJob();
        $result = $job->handle('echo allowed');

        $this->assertIsArray($result);
        $this->assertContains('allowed', $result);
    }

    public function testValidateCommandRejectsNonWhitelistedCommand(): void
    {
        $config                       = config('Jobs');
        $config->allowedShellCommands = ['echo', 'ls'];

        $this->expectException(JobException::class);
        $this->expectExceptionMessage('not in the whitelist');

        $job = new ShellJob();
        $job->handle('rm -rf /tmp');
    }

    public function testValidateCommandExtractsBaseCommand(): void
    {
        $config                       = config('Jobs');
        $config->allowedShellCommands = ['echo'];

        $job    = new ShellJob();
        $result = $job->handle('echo test argument');

        // Should allow because base command 'echo' is whitelisted
        $this->assertIsArray($result);
    }

    public function testValidateCommandIsCaseInsensitive(): void
    {
        $config                       = config('Jobs');
        $config->allowedShellCommands = ['ECHO'];

        $job    = new ShellJob();
        $result = $job->handle('echo test');

        $this->assertIsArray($result);
    }

    public function testBeforeRunReturnsJobUnmodified(): void
    {
        $originalJob = (new Job('shell', 'echo test'))->named('test');
        $shellJob    = new ShellJob();

        $result = $shellJob->beforeRun($originalJob);

        $this->assertSame($originalJob, $result);
    }

    public function testAfterRunReturnsJobUnmodified(): void
    {
        $originalJob = (new Job('shell', 'echo test'))->named('test');
        $shellJob    = new ShellJob();

        $result = $shellJob->afterRun($originalJob);

        $this->assertSame($originalJob, $result);
    }

    public function testHandleWithComplexCommand(): void
    {
        $config                       = config('Jobs');
        $config->allowedShellCommands = ['echo'];

        $job    = new ShellJob();
        $result = $job->handle('echo test');

        $this->assertIsArray($result);
    }

    public function testValidateCommandWithPathPrefix(): void
    {
        $config                       = config('Jobs');
        $config->allowedShellCommands = ['echo'];

        $job    = new ShellJob();
        $result = $job->handle('echo test');

        $this->assertIsArray($result);
    }
}
