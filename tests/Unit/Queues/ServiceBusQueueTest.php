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

use Daycry\Jobs\Job;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\ServiceBusQueue;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class ServiceBusQueueTest extends TestCase
{
    private function makeQueue(array $responses): ServiceBusQueue
    {
        // Anonymous subclass overriding client() to return stub with queued responses
        return new class ($responses) extends ServiceBusQueue {
            /**
             * @param array<int, object> $responses
             */
            public function __construct(private array $responses)
            {
                parent::__construct();
            }

            protected function client(): object
            {
                $responses = &$this->responses;

                return new class ($responses) {
                    public function __construct(private array &$responses)
                    {
                    }

                    private function pop(): object
                    {
                        return array_shift($this->responses) ?? new class () {
                            public function getStatusCode(): int
                            {
                                return 500;
                            }

                            public function getBody(): string
                            {
                                return '{}';
                            }
                        };
                    }

                    /**
                     * @param array<string, mixed> $options
                     */
                    public function post(string $url, array $options = []): object
                    {
                        return $this->pop();
                    }

                    /**
                     * @param array<string, mixed> $options
                     */
                    public function delete(string $url, array $options = []): object
                    {
                        return $this->pop();
                    }
                };
            }
        };
    }

    public function testEnqueueSuccessAndFailure(): void
    {
        $successResp = new class () {
            public function getStatusCode(): int
            {
                return 201;
            }

            public function getBody(): string
            {
                return '{}';
            }
        };
        $failResp = new class () {
            public function getStatusCode(): int
            {
                return 500;
            }

            public function getBody(): string
            {
                return '{}';
            }
        };
        $q   = $this->makeQueue([$successResp]);
        $obj = (object) ['queue' => 'default', 'payload' => 'x'];
        $id  = $q->enqueue($obj);
        $this->assertNotSame('', $id);
        $q2 = $this->makeQueue([$failResp]);
        $this->assertSame('', $q2->enqueue($obj));
    }

    public function testWatchSuccessAndNull(): void
    {
        $bodyObj = (object) ['createdAt' => '2025-01-02 03:04:05', 'attempts' => 2, 'payload' => 'x'];
        // v1.1+ peek-lock: 201 Created with BrokerProperties header carrying LockToken + MessageId.
        $okResp = new class ($bodyObj) {
            public function __construct(private readonly object $b)
            {
            }

            public function getStatusCode(): int
            {
                return 201;
            }

            public function getBody(): string
            {
                return (string) json_encode($this->b);
            }

            public function getHeader(string $name): mixed
            {
                if ($name !== 'BrokerProperties') {
                    return null;
                }

                return new class () {
                    public function getValue(): string
                    {
                        return (string) json_encode([
                            'MessageId' => 'test-msg-id-1',
                            'LockToken' => '00000000-0000-0000-0000-000000000001',
                        ]);
                    }
                };
            }
        };
        // 204 No Content = empty queue under peek-lock semantics.
        $empty = new class () {
            public function getStatusCode(): int
            {
                return 204;
            }

            public function getBody(): string
            {
                return '';
            }

            public function getHeader(string $name): mixed
            {
                return null;
            }
        };

        $q   = $this->makeQueue([$okResp]);
        $env = $q->watch('default');
        $this->assertInstanceOf(JobEnvelope::class, $env);
        $this->assertSame(2, $env->attempts);
        $this->assertSame('test-msg-id-1', $env->id);

        $q2 = $this->makeQueue([$empty]);
        $this->assertNull($q2->watch('default'));
    }

    public function testRemoveJobWithRecreate(): void
    {
        $q   = $this->makeQueue([]);
        $job = new class ('default') extends Job {
            public function __construct(protected string $queueName)
            {
                parent::__construct();
                $this->setQueue($this->queueName);
            }
        };
        $this->assertTrue($q->removeJob($job, true));
    }

    public function testEnqueueWithScheduledDelay(): void
    {
        $successResp = new class () {
            public function getStatusCode(): int
            {
                return 201;
            }

            public function getBody(): string
            {
                return '{}';
            }
        };
        $q   = $this->makeQueue([$successResp]);
        $obj = (object) [
            'queue'    => 'default',
            'payload'  => 'x',
            'schedule' => new DateTimeImmutable('+1 hour'), // triggers scheduled path
        ];
        $id = $q->enqueue($obj);
        $this->assertNotSame('', $id);
    }

    public function testEnqueueWithLabel(): void
    {
        $successResp = new class () {
            public function getStatusCode(): int
            {
                return 201;
            }

            public function getBody(): string
            {
                return '{}';
            }
        };
        $q   = $this->makeQueue([$successResp]);
        $obj = (object) [
            'queue'   => 'default',
            'payload' => 'x',
            'label'   => 'my-label',
        ];
        $id = $q->enqueue($obj);
        $this->assertNotSame('', $id);
    }

    public function testWatchDeserializationFailureReturnsNull(): void
    {
        // Peek-lock returns 201 with valid headers but body is unreadable;
        // the message stays locked (we do not ack) so the broker can redeliver.
        $okRespInvalidBody = new class () {
            public function getStatusCode(): int
            {
                return 201;
            }

            public function getBody(): string
            {
                return 'not-json';
            }

            public function getHeader(string $name): mixed
            {
                if ($name !== 'BrokerProperties') {
                    return null;
                }

                return new class () {
                    public function getValue(): string
                    {
                        return (string) json_encode([
                            'MessageId' => 'corrupt-msg',
                            'LockToken' => '00000000-0000-0000-0000-000000000099',
                        ]);
                    }
                };
            }
        };
        $q   = $this->makeQueue([$okRespInvalidBody]);
        $env = $q->watch('default');
        $this->assertNull($env);
    }
}
