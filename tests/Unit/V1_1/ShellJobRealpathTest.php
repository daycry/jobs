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

namespace Tests\Unit\V1_1;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Jobs\ShellJob;
use ReflectionMethod;
use Tests\Support\TestCase;

/**
 * v1.1 ShellJob whitelist now compares absolute paths via realpath() while preserving
 * basename matching as a deprecated fallback for backwards compatibility.
 *
 * @internal
 */
final class ShellJobRealpathTest extends TestCase
{
    private function callValidate(ShellJob $job, string $command): void
    {
        $ref = new ReflectionMethod($job, 'validateCommand');
        $ref->invoke($job, $command);
    }

    public function testEmptyWhitelistAllowsEverything(): void
    {
        config('Jobs')->allowedShellCommands = [];

        $this->callValidate(new ShellJob(), '/usr/bin/anything --flag');
        $this->callValidate(new ShellJob(), 'rm -rf /');

        $this->addToAssertionCount(2);
    }

    public function testRealpathWhitelistAcceptsExactPath(): void
    {
        // Use the PHP binary itself — guaranteed to exist on every CI runner.
        $php = (string) realpath(PHP_BINARY);
        if ($php === '') {
            $this->markTestSkipped('Cannot resolve PHP_BINARY for realpath comparison.');
        }

        config('Jobs')->allowedShellCommands = [$php];

        $this->callValidate(new ShellJob(), $php . ' --version');
        $this->addToAssertionCount(1);
    }

    public function testRealpathWhitelistRejectsPathOutsideWhitelist(): void
    {
        $this->expectException(JobException::class);

        $php = (string) realpath(PHP_BINARY);
        if ($php === '') {
            $this->markTestSkipped('Cannot resolve PHP_BINARY for realpath comparison.');
        }

        config('Jobs')->allowedShellCommands = [$php];
        // Pretend the user encolar a binary that is NOT the configured PHP path.
        $this->callValidate(new ShellJob(), '/tmp/__not_a_real_binary__ args');
    }

    public function testLegacyBasenameModeStillMatchesBareNames(): void
    {
        // Bare entries (no separators) keep working through the BC fallback so
        // existing installations do not break when upgrading to v1.1.
        config('Jobs')->allowedShellCommands = ['ls'];

        $this->callValidate(new ShellJob(), 'ls -la');
        $this->addToAssertionCount(1);
    }

    public function testLegacyBasenameModeRejectsUnknownNames(): void
    {
        $this->expectException(JobException::class);

        config('Jobs')->allowedShellCommands = ['ls'];
        $this->callValidate(new ShellJob(), 'rm -rf /');
    }
}
