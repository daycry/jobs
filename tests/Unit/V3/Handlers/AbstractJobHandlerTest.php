<?php

declare(strict_types=1);

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
