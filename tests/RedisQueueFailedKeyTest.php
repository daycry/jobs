<?php

declare(strict_types=1);

use Daycry\Jobs\Queues\RedisQueue;
use PHPUnit\Framework\TestCase;

/** @internal */
final class RedisQueueFailedKeyTest extends TestCase
{
    public function testFailedKeyFormatViaReflection(): void
    {
        $q = new RedisQueue();
        $ref = new ReflectionClass($q);
        $method = $ref->getMethod('failedKey');
        $method->setAccessible(true);
        $key = $method->invoke($q, 'default');
        $this->assertSame('jobs:default-failed', $key);
    }
}
