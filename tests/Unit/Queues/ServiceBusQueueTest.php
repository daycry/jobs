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
        $okResp  = new class ($bodyObj) {
            public function __construct(private readonly object $b)
            {
            }

            public function getStatusCode(): int
            {
                return 200;
            }

            public function getBody(): string
            {
                return (string) json_encode($this->b);
            }
        };
        $notFound = new class () {
            public function getStatusCode(): int
            {
                return 404;
            }

            public function getBody(): string
            {
                return '';
            }
        };
        $q   = $this->makeQueue([$okResp]);
        $env = $q->watch('default');
        $this->assertInstanceOf(JobEnvelope::class, $env);
        $this->assertSame(2, $env->attempts);
        $q2 = $this->makeQueue([$notFound]);
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
}
