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
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\CommandHandler;

/**
 * @internal
 */
final class CommandHandlerTest extends CIUnitTestCase
{
    public function testRejectsEmptyOrNonString(): void
    {
        $this->expectException(JobException::class);
        (new CommandHandler())->handle(JobContext::fromPayload(''));
    }

    public function testRunsFrameworkCommand(): void
    {
        // command() returns the captured CLI output; jobs:test is registered in tests/_support.
        $result = (new CommandHandler())->handle(JobContext::fromPayload('jobs:test'));
        $this->assertIsString($result);
    }
}
