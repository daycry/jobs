<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\ClosureHandler;

/**
 * @internal
 */
final class ClosureHandlerTest extends CIUnitTestCase
{
    public function testInvokesCallablePayload(): void
    {
        $handler = new ClosureHandler();
        $result  = $handler->handle(JobContext::fromPayload(static fn () => 'ok'));
        $this->assertSame('ok', $result);
    }

    public function testRejectsNonCallable(): void
    {
        $this->expectException(JobException::class);
        (new ClosureHandler())->handle(JobContext::fromPayload('not-callable'));
    }
}
