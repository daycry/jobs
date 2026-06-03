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

namespace Tests\Unit\V3\Coverage;

use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\Backends\ServiceBusBackend;
use Daycry\Jobs\Queues\Backends\ServiceBusTransport;
use Daycry\Jobs\Queues\JobLease;
use stdClass;

/**
 * Recording fake transport that also captures the request HEADERS (so the scheduled-enqueue
 * branch can be asserted) and serves a FIFO queue of stub post() responses.
 *
 * @internal
 */
final class CovFakeServiceBusTransport implements ServiceBusTransport
{
    /**
     * @var list<array{op: string, url: string, body: ?string, headers: array<string, string>}>
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
        $this->ops[] = ['op' => 'post', 'url' => $url, 'body' => $body, 'headers' => $headers];

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
        $this->ops[] = ['op' => 'delete', 'url' => $url, 'body' => null, 'headers' => $headers];

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
     * @return list<array{op: string, url: string, body: ?string, headers: array<string, string>}>
     */
    public function opsOf(string $op): array
    {
        return array_values(array_filter($this->ops, static fn (array $entry): bool => $entry['op'] === $op));
    }
}

/**
 * Covers the not-yet-exercised branches of {@see ServiceBusBackend}: enqueue with a future
 * scheduledAt (ScheduledEnqueueTimeUtc), fetch with a non-201 (no message) response, nack with a
 * delay (scheduled re-enqueue), abandon, and reapExpired == 0.
 *
 * @internal
 */
final class CovServiceBusBackendTest extends CIUnitTestCase
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

    /**
     * A server-error / no-message response (status != 201) so fetch() must return null.
     */
    private function serverErrorResponse(): object
    {
        return new class () {
            public function getStatusCode(): int
            {
                return 500;
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

    public function testEnqueueWithFutureScheduleSetsScheduledEnqueueTimeUtcHeader(): void
    {
        $transport = new CovFakeServiceBusTransport();
        $backend   = new ServiceBusBackend($transport);

        $backend->enqueue(new JobDefinition(
            handler: 'command',
            payload: 'jobs:test',
            queue: 'covsbq',
            scheduledAt: new DateTimeImmutable('+3600 seconds'),
        ));

        $posts = $transport->opsOf('post');
        $this->assertCount(1, $posts);

        // The scheduled branch builds a BrokerProperties header carrying ScheduledEnqueueTimeUtc.
        $headers = $posts[0]['headers'];
        $this->assertArrayHasKey('BrokerProperties', $headers);
        $broker = json_decode($headers['BrokerProperties']);
        $this->assertInstanceOf(stdClass::class, $broker);
        $this->assertObjectHasProperty('ScheduledEnqueueTimeUtc', $broker);
        $this->assertGreaterThan(time(), (int) $broker->ScheduledEnqueueTimeUtc);
    }

    public function testFetchReturnsNullWhenResponseIsNot201(): void
    {
        $transport = new CovFakeServiceBusTransport([$this->serverErrorResponse()]);
        $backend   = new ServiceBusBackend($transport);

        $this->assertNotInstanceOf(JobLease::class, $backend->fetch('covsbq'));

        // No settle/delete happens when there was no usable message.
        $this->assertCount(0, $transport->opsOf('delete'));
    }

    public function testNackWithDelayReenqueuesScheduledAndDeletesLock(): void
    {
        $wire      = (object) ['job' => 'command', 'payload' => 'x', 'queue' => 'covsbq', 'identifier' => 'abc123', 'attempts' => 0];
        $transport = new CovFakeServiceBusTransport([
            $this->lockedResponse((string) json_encode($wire), 'msg-9', 'lock-9'),
        ]);
        $backend = new ServiceBusBackend($transport);

        $lease = $backend->fetch('covsbq');
        $this->assertInstanceOf(JobLease::class, $lease);

        // delay > 0 => the re-enqueue post carries a ScheduledEnqueueTimeUtc broker property.
        $this->assertTrue($backend->nack($lease, 120));

        $bodied = array_values(array_filter(
            $transport->opsOf('post'),
            static fn (array $entry): bool => $entry['body'] !== null,
        ));
        $this->assertCount(1, $bodied);

        $resent = json_decode($bodied[0]['body']);
        $this->assertInstanceOf(stdClass::class, $resent);
        $this->assertSame(1, $resent->attempts);

        $broker = json_decode($bodied[0]['headers']['BrokerProperties']);
        $this->assertInstanceOf(stdClass::class, $broker);
        $this->assertObjectHasProperty('ScheduledEnqueueTimeUtc', $broker);

        // Original lock settled afterwards.
        $deletes = $transport->opsOf('delete');
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('/covsbq/messages/msg-9/lock-9', $deletes[0]['url']);
    }

    public function testAbandonDeletesLockWithoutReenqueue(): void
    {
        $wire      = (object) ['job' => 'command', 'payload' => 'x', 'identifier' => 'abc123', 'attempts' => 0];
        $transport = new CovFakeServiceBusTransport([
            $this->lockedResponse((string) json_encode($wire), 'msg-7', 'lock-7'),
        ]);
        $backend = new ServiceBusBackend($transport);

        $lease = $backend->fetch('covsbq');
        $this->assertInstanceOf(JobLease::class, $lease);

        $this->assertTrue($backend->abandon($lease));

        $deletes = $transport->opsOf('delete');
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('/covsbq/messages/msg-7/lock-7', $deletes[0]['url']);

        // No re-enqueue: the only post carried no body (the peek-lock receive).
        $bodied = array_values(array_filter(
            $transport->opsOf('post'),
            static fn (array $entry): bool => $entry['body'] !== null,
        ));
        $this->assertCount(0, $bodied);
    }

    public function testReapExpiredReturnsZeroAndHitsNoTransport(): void
    {
        $transport = new CovFakeServiceBusTransport();
        $backend   = new ServiceBusBackend($transport);

        $this->assertSame(0, $backend->reapExpired('covsbq', 60));
        $this->assertCount(0, $transport->ops);
    }
}
