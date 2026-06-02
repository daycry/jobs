<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Queues\Backends\SyncBackend;
use Daycry\Jobs\V2\JobDefinition;

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
