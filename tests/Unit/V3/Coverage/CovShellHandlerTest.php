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
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\ShellHandler;

/**
 * @internal
 */
final class CovShellHandlerTest extends CIUnitTestCase
{
    private function config(): Jobs
    {
        return config(Jobs::class) ?? new Jobs();
    }

    public function testRejectsUnsupportedPayloadType(): void
    {
        // toArgv() throws for a payload that is neither string nor array.
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('string or an argv array');
        (new ShellHandler())->handle(JobContext::fromPayload(123));
    }

    public function testRejectsEmptyStringPayload(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = true;

        // A whitespace-only string yields an empty argv -> non-empty command error.
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('non-empty command');
        (new ShellHandler())->handle(JobContext::fromPayload('   '));
    }

    public function testDenyByDefaultWithEmptyAllowlist(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = false;

        $this->expectException(JobException::class);
        $this->expectExceptionMessage('deny-by-default');
        (new ShellHandler())->handle(JobContext::fromPayload('echo hi'));
    }

    public function testAllowAllEscapeHatchRunsStringPayload(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = true;

        $result = (new ShellHandler())->handle(
            JobContext::fromPayload(PHP_BINARY . ' -r echo("hi");'),
        );
        $this->assertIsArray($result);
    }

    public function testAllowAllEscapeHatchRunsArrayPayload(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = true;

        $result = (new ShellHandler())->handle(
            JobContext::fromPayload([PHP_BINARY, '-r', 'echo "hi";']),
        );
        $this->assertIsArray($result);
        $this->assertContains('hi', $result);
    }

    public function testRejectsBinaryNotInAllowlist(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = ['/usr/bin/echo'];
        $cfg->allowAllShellCommands = false;

        $this->expectException(JobException::class);
        $this->expectExceptionMessage('whitelist');
        (new ShellHandler())->handle(JobContext::fromPayload(['/bin/totally-not-there', '-x']));
    }
}
