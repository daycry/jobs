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

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\Backends\ServiceBusBackend;
use Daycry\Jobs\Queues\Backends\ServiceBusTransport;
use Daycry\Jobs\Queues\JobLease;
use stdClass;

/**
 * Recording fake {@see ServiceBusTransport}: serves the queued post() responses (FIFO) and
 * records every call (verb + url + body) so a test can assert on the broker interaction without
 * touching the network.
 *
 * @internal
 */
final class RecordingServiceBusTransport implements ServiceBusTransport
{
    /**
     * @var list<array{op: string, url: string, body: ?string}>
     */
    public array $ops = [];

    /**
     * @param list<object> $postResponses
     */
    public function __construct(private array $postResponses = [])
    {
    }

    public function post(string $url, array $headers, ?string $body = null): object
    {
        $this->ops[] = ['op' => 'post', 'url' => $url, 'body' => $body];

        $next = array_shift($this->postResponses);

        return $next ?? new class () {
            public function getStatusCode(): int
            {
                return 201;
            }

            public function getBody(): string
            {
                return '';
            }
        };
    }

    public function delete(string $url, array $headers): object
    {
        $this->ops[] = ['op' => 'delete', 'url' => $url, 'body' => null];

        return new class () {
            public function getStatusCode(): int
            {
                return 200;
            }

            public function getBody(): string
            {
                return '';
            }
        };
    }

    /**
     * Return only the recorded operations matching the given verb.
     *
     * @return list<array{op: string, url: string, body: ?string}>
     */
    public function opsOf(string $op): array
    {
        return array_values(array_filter($this->ops, static fn (array $entry): bool => $entry['op'] === $op));
    }
}

/**
 * Verifies the v3 ServiceBusBackend without touching the network: the {@see ServiceBusTransport}
 * HTTP seam is replaced by {@see RecordingServiceBusTransport}.
 *
 * @internal
 */
final class ServiceBusBackendTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $config             = config('Jobs');
        $config->serviceBus = [
            'url'    => 'https://example.servicebus.windows.net/ns',
            'issuer' => 'test-policy',
            'secret' => 'test-secret',
        ];
    }

    /**
     * Build a peek-lock "201 Created" stub response carrying the given wire body and a
     * BrokerProperties header with the MessageId/LockToken pair.
     */
    private function lockedResponse(string $wireJson, string $messageId, string $lockToken): object
    {
        return new class ($wireJson, $messageId, $lockToken) {
            public function __construct(
                private readonly string $wireJson,
                private readonly string $messageId,
                private readonly string $lockToken,
            ) {
            }

            public function getStatusCode(): int
            {
                return 201;
            }

            public function getBody(): string
            {
                return $this->wireJson;
            }

            public function getHeader(string $name): mixed
            {
                if ($name !== 'BrokerProperties') {
                    return null;
                }

                return new class ($this->messageId, $this->lockToken) {
                    public function __construct(private readonly string $messageId, private readonly string $lockToken)
                    {
                    }

                    public function getValue(): string
                    {
                        return (string) json_encode([
                            'MessageId' => $this->messageId,
                            'LockToken' => $this->lockToken,
                        ]);
                    }
                };
            }
        };
    }

    private function emptyResponse(): object
    {
        return new class () {
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
    }

    public function testEnqueuePostsMessageAndReturnsIdentifier(): void
    {
        $transport = new RecordingServiceBusTransport();
        $backend   = new ServiceBusBackend($transport);

        $id = $backend->enqueue(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'sbq', maxRetries: 2));

        $this->assertNotSame('', $id);

        $posts = $transport->opsOf('post');
        $this->assertCount(1, $posts);
        $this->assertStringEndsWith('/sbq/messages', $posts[0]['url']);

        // The posted body is the canonical wire payload (attempts=0, identifier echoed).
        $body = $posts[0]['body'];
        $this->assertIsString($body);
        $wire = json_decode($body);
        $this->assertInstanceOf(stdClass::class, $wire);
        $this->assertSame('command', $wire->job);
        $this->assertSame('jobs:test', $wire->payload);
        $this->assertSame($id, $wire->identifier);
        $this->assertSame(0, $wire->attempts);
    }

    public function testFetchMapsPeekLockResponseToLease(): void
    {
        $wire = (object) [
            'job'        => 'command',
            'payload'    => 'jobs:test',
            'queue'      => 'sbq',
            'priority'   => 5,
            'maxRetries' => 2,
            'attempts'   => 0,
            'name'       => 'demo',
            'identifier' => 'abc123',
            'schedule'   => null,
        ];
        $transport = new RecordingServiceBusTransport([
            $this->lockedResponse((string) json_encode($wire), 'msg-1', 'lock-1'),
        ]);
        $backend = new ServiceBusBackend($transport);

        $lease = $backend->fetch('sbq');

        $this->assertInstanceOf(JobLease::class, $lease);
        $this->assertSame('servicebus', $lease->backend);
        $this->assertSame('sbq', $lease->envelope->queue);
        $this->assertSame('abc123', $lease->envelope->id);
        $this->assertSame('demo', $lease->envelope->name);
        $this->assertSame(0, $lease->envelope->attempts);
        $this->assertIsObject($lease->envelope->payload);
        $this->assertSame('command', $lease->envelope->payload->job);
        $this->assertSame('msg-1', $lease->envelope->meta['messageId']);
        $this->assertSame('lock-1', $lease->envelope->meta['lockToken']);

        // The lease token is the settle/lock location built from messageId + lockToken.
        $this->assertStringContainsString('/sbq/messages/msg-1/lock-1', $lease->token);
    }

    public function testFetchReturnsNullWhenQueueEmpty(): void
    {
        $transport = new RecordingServiceBusTransport([$this->emptyResponse()]);
        $backend   = new ServiceBusBackend($transport);

        $this->assertNotInstanceOf(JobLease::class, $backend->fetch('sbq'));
    }

    public function testAckDeletesTheLock(): void
    {
        $wire      = (object) ['job' => 'command', 'payload' => 'x', 'identifier' => 'abc123', 'attempts' => 0];
        $transport = new RecordingServiceBusTransport([
            $this->lockedResponse((string) json_encode($wire), 'msg-1', 'lock-1'),
        ]);
        $backend = new ServiceBusBackend($transport);

        $lease = $backend->fetch('sbq');
        $this->assertInstanceOf(JobLease::class, $lease);

        $this->assertTrue($backend->ack($lease));

        $deletes = $transport->opsOf('delete');
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('/sbq/messages/msg-1/lock-1', $deletes[0]['url']);
    }

    public function testNackReenqueuesWithIncrementedAttemptsThenDeletes(): void
    {
        $wire      = (object) ['job' => 'command', 'payload' => 'x', 'queue' => 'sbq', 'identifier' => 'abc123', 'attempts' => 0];
        $transport = new RecordingServiceBusTransport([
            $this->lockedResponse((string) json_encode($wire), 'msg-1', 'lock-1'),
        ]);
        $backend = new ServiceBusBackend($transport);

        $lease = $backend->fetch('sbq');
        $this->assertInstanceOf(JobLease::class, $lease);

        $this->assertTrue($backend->nack($lease, 0));

        // A re-enqueue (post WITH a body) happened with attempts+1; the receive post carried no body.
        $bodied = array_values(array_filter(
            $transport->opsOf('post'),
            static fn (array $entry): bool => $entry['body'] !== null,
        ));
        $this->assertCount(1, $bodied);
        $resent = json_decode($bodied[0]['body']);
        $this->assertInstanceOf(stdClass::class, $resent);
        $this->assertSame(1, $resent->attempts);
        $this->assertSame('abc123', $resent->identifier);
        $this->assertStringEndsWith('/sbq/messages', $bodied[0]['url']);

        // Then the original lock is settled (deleted).
        $deletes = $transport->opsOf('delete');
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('/sbq/messages/msg-1/lock-1', $deletes[0]['url']);
    }

    public function testAbandonDeletesTheLockWithoutReenqueue(): void
    {
        $wire      = (object) ['job' => 'command', 'payload' => 'x', 'identifier' => 'abc123', 'attempts' => 0];
        $transport = new RecordingServiceBusTransport([
            $this->lockedResponse((string) json_encode($wire), 'msg-1', 'lock-1'),
        ]);
        $backend = new ServiceBusBackend($transport);

        $lease = $backend->fetch('sbq');
        $this->assertInstanceOf(JobLease::class, $lease);

        $this->assertTrue($backend->abandon($lease));

        $deletes = $transport->opsOf('delete');
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('/sbq/messages/msg-1/lock-1', $deletes[0]['url']);

        // abandon does not re-enqueue: the only post was the peek-lock receive (no body).
        $bodiedPosts = array_values(array_filter(
            $transport->opsOf('post'),
            static fn (array $entry): bool => $entry['body'] !== null,
        ));
        $this->assertCount(0, $bodiedPosts);
    }

    public function testReapExpiredReturnsZeroAndHitsNoTransport(): void
    {
        $transport = new RecordingServiceBusTransport();
        $backend   = new ServiceBusBackend($transport);

        $this->assertSame(0, $backend->reapExpired('sbq', 60));
        $this->assertCount(0, $transport->ops);
    }
}
