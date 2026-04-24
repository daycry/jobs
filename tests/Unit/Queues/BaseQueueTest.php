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

use Daycry\Jobs\Generators\UuidGenerator;
use Daycry\Jobs\Interfaces\IdGeneratorInterface;
use Daycry\Jobs\Interfaces\PayloadSerializerInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\DelayResult;
use Daycry\Jobs\Libraries\JsonPayloadSerializer;
use Daycry\Jobs\Queues\BaseQueue;
use PHPUnit\Framework\TestCase;

// Concrete implementation for testing
class ConcreteBaseQueue extends BaseQueue
{
    public function enqueue(object $data): string
    {
        return 'id';
    }

    public function watch(string $queue): mixed
    {
        return null;
    }

    public function removeJob(Job $job, bool $recreate = false): bool
    {
        return true;
    }

    // Expose protected methods for testing
    public function testCalculateDelay(object $data): DelayResult
    {
        return $this->calculateDelay($data);
    }

    public function testGetDelayResult(): DelayResult
    {
        return $this->getDelayResult();
    }

    public function testGenerateId(?string $prefix = null, int $bytes = 8): string
    {
        return $this->generateId($prefix, $bytes);
    }

    public function testGetSerializer(): PayloadSerializerInterface
    {
        return $this->getSerializer();
    }

    public function testGetIdGenerator(): IdGeneratorInterface
    {
        return $this->getIdGenerator();
    }
}

/**
 * @internal
 */
final class BaseQueueTest extends TestCase
{
    public function testCalculateDelayWithNullSchedule(): void
    {
        $q      = new ConcreteBaseQueue();
        $data   = (object) ['queue' => 'default'];
        $result = $q->testCalculateDelay($data);

        $this->assertTrue($result->isImmediate());
    }

    public function testCalculateDelayWithFutureSchedule(): void
    {
        $q      = new ConcreteBaseQueue();
        $future = new DateTimeImmutable('+1 hour');
        $data   = (object) ['queue' => 'default', 'schedule' => $future];
        $result = $q->testCalculateDelay($data);

        $this->assertFalse($result->isImmediate());
    }

    public function testGetDelayResultReturnsDefault(): void
    {
        $q      = new ConcreteBaseQueue();
        $result = $q->testGetDelayResult();

        $this->assertSame(0, $result->seconds);
    }

    public function testGetDelayResultAfterCalculate(): void
    {
        $q    = new ConcreteBaseQueue();
        $data = (object) ['queue' => 'default'];
        $q->testCalculateDelay($data);
        $result = $q->testGetDelayResult();

        $this->assertGreaterThanOrEqual(0, $result->seconds);
    }

    public function testSetIdGenerator(): void
    {
        $q         = new ConcreteBaseQueue();
        $generator = new UuidGenerator();
        $returned  = $q->setIdGenerator($generator);

        $this->assertSame($q, $returned);
        $this->assertSame($generator, $q->testGetIdGenerator());
    }

    public function testGetIdGeneratorCreatesDefault(): void
    {
        $q         = new ConcreteBaseQueue();
        $generator = $q->testGetIdGenerator();

        $this->assertInstanceOf(UuidGenerator::class, $generator);
    }

    public function testSetSerializer(): void
    {
        $q          = new ConcreteBaseQueue();
        $serializer = new JsonPayloadSerializer();
        $returned   = $q->setSerializer($serializer);

        $this->assertSame($q, $returned);
        $this->assertSame($serializer, $q->testGetSerializer());
    }

    public function testGetSerializerCreatesDefault(): void
    {
        $q          = new ConcreteBaseQueue();
        $serializer = $q->testGetSerializer();

        $this->assertInstanceOf(JsonPayloadSerializer::class, $serializer);
    }

    public function testGenerateIdReturnsString(): void
    {
        $q  = new ConcreteBaseQueue();
        $id = $q->testGenerateId();

        $this->assertNotSame('', $id);
    }

    public function testGenerateIdWithPrefix(): void
    {
        $q  = new ConcreteBaseQueue();
        $id = $q->testGenerateId('pfx', 4);

        $this->assertNotSame('', $id);
    }
}
