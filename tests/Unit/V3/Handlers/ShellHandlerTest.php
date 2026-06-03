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

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\ShellHandler;

/**
 * @internal
 */
final class ShellHandlerTest extends CIUnitTestCase
{
    private function config(): Jobs
    {
        return config(Jobs::class) ?? new Jobs();
    }

    public function testEmptyWhitelistDeniesByDefault(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = false;

        $this->expectException(JobException::class);
        $this->expectExceptionMessage('deny-by-default');
        (new ShellHandler())->handle(JobContext::fromPayload('echo hi'));
    }

    public function testAllowAllEscapeHatchRuns(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = [];
        $cfg->allowAllShellCommands = true;

        // Use the PHP binary (always present, cross-platform) instead of a shell builtin.
        $result = (new ShellHandler())->handle(JobContext::fromPayload([PHP_BINARY, '-r', 'echo "hi";']));
        $this->assertIsArray($result);
        $this->assertContains('hi', $result);
    }

    public function testRejectsBinaryNotInAllowlist(): void
    {
        $cfg                        = $this->config();
        $cfg->allowedShellCommands  = ['/usr/bin/echo'];
        $cfg->allowAllShellCommands = false;

        $this->expectException(JobException::class);
        (new ShellHandler())->handle(JobContext::fromPayload(['/bin/rm', '-rf', '/tmp/x']));
    }
}
