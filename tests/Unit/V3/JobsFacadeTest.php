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

namespace Tests\Unit\V3;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Jobs;
use Daycry\Jobs\Queues\Backends\SyncBackend;

/**
 * @internal
 */
final class JobsFacadeTest extends CIUnitTestCase
{
    public function testDefineReturnsBuilderThatBuildsDefinition(): void
    {
        $definition = Jobs::define('command', 'jobs:test')->toDefinition();
        $this->assertSame('command', $definition->handler);
    }

    public function testDefineBuildsExpectedDefinition(): void
    {
        $definition = Jobs::define('command', 'jobs:test')->queue('q')->toDefinition();

        $this->assertSame('command', $definition->handler);
        $this->assertSame('jobs:test', $definition->payload);
        $this->assertSame('q', $definition->queue);
    }

    public function testBackendResolvesNamedBackend(): void
    {
        $this->assertInstanceOf(SyncBackend::class, Jobs::backend('sync'));
    }

    public function testDispatchThroughFacadeRunsSyncInline(): void
    {
        $ran = false;

        $id = Jobs::define('closure', static function () use (&$ran): string {
            $ran = true;

            return 'ok';
        })->dispatch('sync');

        $this->assertTrue($ran);
        $this->assertStringStartsWith('sync-', $id);
    }
}
