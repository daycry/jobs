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

use PHPUnit\Framework\TestCase;

/** @internal */
final class RedisQueueFailedKeyTest extends TestCase
{
    public function testFailedKeyFormatViaReflection(): void
    {
        // failedKey() method removed in queue simplification refactor (unused)
        // Future implementation will handle failed jobs differently
        $this->markTestSkipped('failedKey method removed; failed job tracking will be reimplemented');
    }
}
