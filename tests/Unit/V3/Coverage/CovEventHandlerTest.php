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

use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\EventHandler;

/**
 * @internal
 */
final class CovEventHandlerTest extends CIUnitTestCase
{
    private function config(): Jobs
    {
        return config(Jobs::class) ?? new Jobs();
    }

    public function testRejectsNonArrayPayload(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('string "name" key');
        (new EventHandler())->handle(JobContext::fromPayload('not-an-array'));
    }

    public function testRejectsMissingName(): void
    {
        $this->expectException(JobException::class);
        (new EventHandler())->handle(JobContext::fromPayload(['data' => []]));
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(JobException::class);
        (new EventHandler())->handle(JobContext::fromPayload(['name' => '']));
    }

    public function testRejectsNonStringName(): void
    {
        $this->expectException(JobException::class);
        (new EventHandler())->handle(JobContext::fromPayload(['name' => 42]));
    }

    public function testRejectsEventNotInAllowlist(): void
    {
        $cfg                = $this->config();
        $cfg->allowedEvents = [];

        $this->expectException(JobException::class);
        $this->expectExceptionMessage('allowedEvents');
        (new EventHandler())->handle(JobContext::fromPayload(['name' => 'user.deleted']));
    }

    public function testTriggersAllowedEventWithArrayData(): void
    {
        $cfg                = $this->config();
        $cfg->allowedEvents = ['cov.event.array'];

        $captured = null;
        Events::on('cov.event.array', static function ($payload) use (&$captured): void {
            $captured = $payload;
        });

        (new EventHandler())->handle(
            JobContext::fromPayload(['name' => 'cov.event.array', 'data' => ['x' => 1]]),
        );

        $this->assertSame(['x' => 1], $captured);
    }

    public function testTriggersAllowedEventWrappingScalarData(): void
    {
        $cfg                = $this->config();
        $cfg->allowedEvents = ['cov.event.scalar'];

        $captured = null;
        Events::on('cov.event.scalar', static function ($payload) use (&$captured): void {
            $captured = $payload;
        });

        // Non-array data is wrapped into [$data] by the handler, then passed as the single
        // argument to Events::trigger, so the listener receives the wrapping array.
        (new EventHandler())->handle(
            JobContext::fromPayload(['name' => 'cov.event.scalar', 'data' => 'hello']),
        );

        $this->assertSame(['hello'], $captured);
    }

    public function testTriggersAllowedEventWithDefaultEmptyData(): void
    {
        $cfg                = $this->config();
        $cfg->allowedEvents = ['cov.event.nodata'];

        $hit = false;
        Events::on('cov.event.nodata', static function () use (&$hit): void {
            $hit = true;
        });

        (new EventHandler())->handle(JobContext::fromPayload(['name' => 'cov.event.nodata']));

        $this->assertTrue($hit);
    }
}
