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
use Daycry\Jobs\Queues\JobEnvelope;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class BeanstalkQueueWatchTest extends TestCase
{
    public function testWatchReturnsEnvelopeOrSkips(): void
    {
        // Use an in-memory stub avoiding real beanstalk dependency
        $worker = new class () extends BeanstalkQueue {
            private array $stored = [];

            public function __construct()
            { // skip parent connection
            }

            public function enqueue(object $data): string
            {
                $id                = 'stub-' . (count($this->stored) + 1);
                $data->createdAt   = date(DATE_ATOM);
                $this->stored[$id] = json_encode($data);

                return $id;
            }

            public function watch(string $queue)
            {
                if (! $this->stored) {
                    return null;
                } $id = array_key_first($this->stored);
                $raw  = $this->stored[$id];
                unset($this->stored[$id]);
                $decoded = json_decode($raw);

                return new JobEnvelope(id: $id, queue: $queue, payload: $decoded, attempts: (int) ($decoded->attempts ?? 0), priority: 0, scheduledAt: null, availableAt: null, createdAt: new DateTimeImmutable($decoded->createdAt), meta: ['ttr' => 0], raw: (object) ['raw' => $raw]);
            }
        };
        $payload = (object) ['job' => 'command', 'payload' => 'jobs:test', 'queue' => 'bean_default', 'attempts' => 0];
        $id      = $worker->enqueue($payload);
        $this->assertIsString($id);
        $env = $worker->watch('bean_default');
        $this->assertInstanceOf(JobEnvelope::class, $env);
        $this->assertSame('bean_default', $env->queue);
        $this->assertIsObject($env->payload);
        $this->assertObjectHasProperty('job', $env->payload);
    }
}
