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

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\QueueException;
use Daycry\Jobs\Queues\BackendFactory;
use Daycry\Jobs\Queues\Backends\DatabaseBackend;
use Daycry\Jobs\Queues\Backends\RedisBackend;
use Daycry\Jobs\Queues\Backends\SyncBackend;

/**
 * @internal
 */
final class BackendFactoryTest extends CIUnitTestCase
{
    public function testMakesNamedBackends(): void
    {
        $config = new Jobs();
        $this->assertInstanceOf(SyncBackend::class, BackendFactory::make($config, 'sync'));
        $this->assertInstanceOf(DatabaseBackend::class, BackendFactory::make($config, 'database'));
        $this->assertInstanceOf(RedisBackend::class, BackendFactory::make($config, 'redis'));
    }

    public function testDefaultsToConfiguredWorker(): void
    {
        $config         = new Jobs();
        $config->worker = 'database';
        $this->assertInstanceOf(DatabaseBackend::class, BackendFactory::make($config));
    }

    public function testThrowsForUnknownBackend(): void
    {
        $this->expectException(QueueException::class);
        BackendFactory::make(new Jobs(), 'does-not-exist');
    }
}
