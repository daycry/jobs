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

use Daycry\Jobs\Exceptions\QueueException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class QueueExceptionTest extends TestCase
{
    public function testForInvalidWorker(): void
    {
        $e = QueueException::forInvalidWorker('myworker');
        $this->assertNotEmpty($e->getMessage());
    }

    public function testForInvalidQueue(): void
    {
        $e = QueueException::forInvalidQueue('myqueue');
        $this->assertNotEmpty($e->getMessage());
    }

    public function testForInvalidConnection(): void
    {
        $e = QueueException::forInvalidConnection('connection failed');
        $this->assertStringContainsString('connection failed', $e->getMessage());
    }
}
