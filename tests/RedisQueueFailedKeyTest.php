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

use Daycry\Jobs\Queues\RedisQueue;
use PHPUnit\Framework\TestCase;

/** @internal */
final class RedisQueueFailedKeyTest extends TestCase
{
    public function testFailedKeyFormatViaReflection(): void
    {
        $q      = new RedisQueue();
        $ref    = new ReflectionClass($q);
        $method = $ref->getMethod('failedKey');
        $method->setAccessible(true);
        $key = $method->invoke($q, 'default');
        $this->assertSame('jobs:default-failed', $key);
    }
}
