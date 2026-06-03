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

namespace Tests\Unit\V3\Execution;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Execution\JobRuntime;
use RuntimeException;

/**
 * @internal
 */
final class JobRuntimeTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        // Reset shared config mutated by the allowlist test.
        config('Jobs')->queueHandlers = [];
        parent::tearDown();
    }

    public function testRunsClosureHandlerSuccessfully(): void
    {
        $result = (new JobRuntime())->run(
            new JobDefinition(handler: 'closure', payload: null),
            JobContext::fromPayload(static fn (): string => 'ok'),
        );

        $this->assertTrue($result->success);
        $this->assertSame('ok', $result->output);
        $this->assertNull($result->error);
    }

    public function testCapturesOutputBuffer(): void
    {
        $result = (new JobRuntime())->run(
            new JobDefinition(handler: 'closure', payload: null),
            JobContext::fromPayload(static function () {
                echo 'hi';

                return null;
            }),
        );

        $this->assertTrue($result->success);
        $this->assertSame('hi', $result->output);
    }

    public function testFailingHandlerProducesFailureResult(): void
    {
        $result = (new JobRuntime())->run(
            new JobDefinition(handler: 'closure', payload: null),
            JobContext::fromPayload(static function (): void {
                throw new RuntimeException('boom');
            }),
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('boom', (string) $result->error);
    }

    public function testUnknownHandlerProducesFailureResult(): void
    {
        $result = (new JobRuntime())->run(
            new JobDefinition(handler: 'does-not-exist', payload: null),
            JobContext::fromPayload(null),
        );

        $this->assertFalse($result->success);
    }

    public function testTimeoutProducesFailureResult(): void
    {
        $result = (new JobRuntime())->run(
            new JobDefinition(handler: 'closure', payload: null, timeout: 1),
            JobContext::fromPayload(static function (): string {
                usleep(1_100_000);

                return 'late';
            }),
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('exceeded maximum execution time', (string) $result->error);
    }

    public function testPerQueueAllowlistBlocksDisallowedHandler(): void
    {
        // Set the allowlist BEFORE constructing the runtime (the registry snapshots config).
        config('Jobs')->queueHandlers = ['reports' => ['command']];

        $result = (new JobRuntime())->run(
            new JobDefinition(handler: 'closure', payload: null, queue: 'reports'),
            new JobContext(payload: static fn (): string => 'x', queue: 'reports'),
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString("not allowed on queue 'reports'", (string) $result->error);
    }
}
