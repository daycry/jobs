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
use CodeIgniter\Test\Mock\MockCache;
use Daycry\Jobs\Execution\SingleInstanceLock;

/**
 * @internal
 */
final class SingleInstanceLockTest extends CIUnitTestCase
{
    public function testOwnershipTokenGuardsAcquireAndRelease(): void
    {
        $lock = new SingleInstanceLock(new MockCache());

        $this->assertTrue($lock->acquire('job', 'A', 60));
        $this->assertFalse($lock->acquire('job', 'B', 60));
        $this->assertFalse($lock->release('job', 'B'));
        $this->assertTrue($lock->release('job', 'A'));
        $this->assertTrue($lock->acquire('job', 'B', 60));
    }
}
