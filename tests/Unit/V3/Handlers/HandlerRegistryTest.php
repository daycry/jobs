<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Handlers\CommandHandler;
use Daycry\Jobs\Handlers\HandlerRegistry;
use Daycry\Jobs\Handlers\JobHandlerInterface;

/**
 * @internal
 */
final class HandlerRegistryTest extends CIUnitTestCase
{
    public function testResolvesConfiguredHandler(): void
    {
        $registry = new HandlerRegistry(config(Jobs::class) ?? new Jobs());
        $handler  = $registry->resolve('command');
        $this->assertInstanceOf(CommandHandler::class, $handler);
        $this->assertInstanceOf(JobHandlerInterface::class, $handler);
    }

    public function testThrowsForUnknownKey(): void
    {
        $this->expectException(JobException::class);
        (new HandlerRegistry(config(Jobs::class) ?? new Jobs()))->resolve('does-not-exist');
    }

    public function testEnforcesPerQueueAllowlist(): void
    {
        $cfg                = config(Jobs::class) ?? new Jobs();
        $cfg->queueHandlers = ['reports' => ['command']];

        $registry = new HandlerRegistry($cfg);
        $this->expectException(JobException::class);
        $registry->resolveForQueue('shell', 'reports');
    }
}
