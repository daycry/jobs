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
            private array $responses;

            public function __construct(array $responses)
            {
                $this->responses = $responses;
                parent::__construct();
            }

            /**
             * @return object
             */
            protected function client()
            {
                $responses = &$this->responses;

                return new class ($responses) {
                    private array $responses;

                    public function __construct(array &$responses)
                    {
                        $this->responses = $responses;
                    }

                    private function pop()
                    {
                        return array_shift($this->responses) ?? new class () {
                            public function getStatusCode()
                            {
                                return 500;
                            }

                            public function getBody()
                            {
                                return '{}';
                            }
                        };
                    }

                    public function post(string $url, array $options = [])
                    {
                        return $this->pop();
                    }

                    public function delete(string $url, array $options = [])
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
            public function getStatusCode()
            {
                return 201;
            }

            public function getBody()
            {
                return '{}';
            }
        };
        $failResp = new class () {
            public function getStatusCode()
            {
                return 500;
            }

            public function getBody()
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
            private $b;

            public function __construct($b)
            {
                $this->b = $b;
            }

            public function getStatusCode()
            {
                return 200;
            }

            public function getBody()
            {
                return json_encode($this->b);
            }
        };
        $notFound = new class () {
            public function getStatusCode()
            {
                return 404;
            }

            public function getBody()
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
            protected string $queueName;

            public function __construct(string $queue)
            {
                parent::__construct();
                $this->queueName = $queue;
                $this->setQueue($queue);
            }
        };
        $this->assertTrue($q->removeJob($job, true));
    }
}
