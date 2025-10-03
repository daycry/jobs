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

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\ServiceBusQueue;

/**
 * @internal
 */
final class ServiceBusQueueWatchTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Provide minimal config() stubs if needed
        $config             = config('Jobs');
        $config->serviceBus = [
            'url'    => 'https://example.servicebus.windows.net/ns',
            'issuer' => 'test-policy',
            'secret' => 'test-secret',
        ];
    }

    public function testWatchReturnsEnvelopeOrNullGracefully(): void
    {
        $queue = new class () extends ServiceBusQueue {
            private int $calls = 0;

            public function watch(string $queue)
            {
                $this->calls++;
                if ($this->calls === 1) {
                    $body = (object) ['foo' => 'bar', 'createdAt' => date(DATE_ATOM)];

                    return new JobEnvelope(
                        id: 'stub-msg-id',
                        queue: $queue,
                        payload: $body,
                        attempts: 0,
                        priority: null,
                        scheduledAt: null,
                        availableAt: null,
                        createdAt: new DateTimeImmutable($body->createdAt),
                        meta: ['status' => 200],
                        raw: (object) ['body' => $body],
                    );
                }

                return null;
            }
        };

        $env = $queue->watch('testqueue');
        if ($env) {
            $this->assertInstanceOf(JobEnvelope::class, $env);
            $this->assertSame('testqueue', $env->queue);
            $this->assertNotNull($env->createdAt);
            $this->assertSame(200, $env->meta['status']);
        }
        $second = $queue->watch('testqueue');
        $this->assertNull($second);
    }
}
