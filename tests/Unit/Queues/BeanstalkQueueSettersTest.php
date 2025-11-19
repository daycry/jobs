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

use Daycry\Jobs\Queues\BeanstalkQueue;
use PHPUnit\Framework\TestCase;

/** @internal */
final class BeanstalkQueueSettersTest extends TestCase
{
    public function testSetPriorityAndTtrFluent(): void
    {
        $ref   = new ReflectionClass(BeanstalkQueue::class);
        $queue = $ref->newInstanceWithoutConstructor();

        // Initialize private properties manually
        foreach (['priority' => 0, 'ttr' => 3600, 'connection' => null] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($queue, $val);
        }
        $this->assertSame($queue, $queue->setPriority(1024));
        $this->assertSame($queue, $queue->setTtr(90));
        $this->assertSame(1024, $queue->getPriority());
        $this->assertSame(90, $queue->getTtr());
    }
}
