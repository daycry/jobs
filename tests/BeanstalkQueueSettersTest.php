<?php

declare(strict_types=1);

use Daycry\Jobs\Queues\BeanstalkQueue;
use PHPUnit\Framework\TestCase;

/** @internal */
final class BeanstalkQueueSettersTest extends TestCase
{
    public function testSetPriorityAndTtrFluent(): void
    {
        $ref = new ReflectionClass(BeanstalkQueue::class);
        $queue = $ref->newInstanceWithoutConstructor();
        // Initialize private properties manually
        foreach (['priority' => 0, 'ttr' => 3600, 'connection' => null] as $prop => $val) {
            $p = $ref->getProperty($prop); $p->setAccessible(true); $p->setValue($queue, $val);
        }
        $this->assertSame($queue, $queue->setPriority(1024));
        $this->assertSame($queue, $queue->setTtr(90));
        $this->assertSame(1024, $queue->getPriority());
        $this->assertSame(90, $queue->getTtr());
    }
}
