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
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\Backends\SyncBackend;

/**
 * @internal
 */
final class SyncBackendTest extends CIUnitTestCase
{
    public function testEnqueueRunsJobInlineAndReturnsSyntheticId(): void
    {
        $ran = false;

        $definition = new JobDefinition(
            handler: 'closure',
            payload: static function () use (&$ran): string {
                $ran = true;

                return 'done';
            },
        );

        $id = (new SyncBackend())->enqueue($definition);

        $this->assertTrue($ran);
        $this->assertStringStartsWith('sync-', $id);
    }

    public function testFetchReturnsNull(): void
    {
        $this->assertNull((new SyncBackend())->fetch('default'));
    }
}
