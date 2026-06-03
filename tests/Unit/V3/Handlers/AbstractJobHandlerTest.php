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
use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\AbstractJobHandler;
use Daycry\Jobs\Handlers\JobHandlerInterface;

/**
 * @internal
 */
final class AbstractJobHandlerTest extends CIUnitTestCase
{
    public function testConcreteHandlerImplementsInterfaceAndDefaultHooksAreNoOps(): void
    {
        $handler = new class () extends AbstractJobHandler {
            public function handle(JobContext $ctx): mixed
            {
                return strtoupper((string) $ctx->payload);
            }
        };

        $this->assertInstanceOf(JobHandlerInterface::class, $handler);

        $ctx = JobContext::fromPayload('hi');
        $handler->beforeRun($ctx);
        $this->assertSame('HI', $handler->handle($ctx));
        $handler->afterRun($ctx, new ExecutionResult(true, 'HI', null, 0.0, 0.0));
    }
}
