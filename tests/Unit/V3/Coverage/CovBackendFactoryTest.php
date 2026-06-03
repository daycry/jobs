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

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\QueueException;
use Daycry\Jobs\Queues\BackendFactory;
use Daycry\Jobs\Queues\Backends\SyncBackend;
use Daycry\Jobs\Queues\QueueBackend;
use stdClass;

/**
 * @internal
 */
final class CovBackendFactoryTest extends CIUnitTestCase
{
    public function testMakeResolvesExplicitName(): void
    {
        $this->assertInstanceOf(SyncBackend::class, BackendFactory::make(new Jobs(), 'sync'));
    }

    public function testMakeDefaultsToConfiguredWorker(): void
    {
        $config         = new Jobs();
        $config->worker = 'sync';
        $this->assertInstanceOf(QueueBackend::class, BackendFactory::make($config));
    }

    public function testMakeThrowsForUnknownName(): void
    {
        $this->expectException(QueueException::class);
        BackendFactory::make(new Jobs(), 'unknown-backend');
    }

    public function testMakeThrowsWhenMappedClassIsNotABackend(): void
    {
        $config           = new Jobs();
        $config->backends = ['bad' => stdClass::class];

        $this->expectException(QueueException::class);
        BackendFactory::make($config, 'bad');
    }
}
