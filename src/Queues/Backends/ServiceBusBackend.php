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

namespace Daycry\Jobs\Queues\Backends;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Libraries\ServiceBusHeaders;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use stdClass;
use Throwable;

/**
 * Azure Service Bus queue implementing the v3 {@see QueueBackend} contract.
 *
 * Ports the proven HTTP peek-lock logic of the legacy {@see \Daycry\Jobs\Queues\ServiceBusQueue}
 * (SAS auth + BrokerProperties built by {@see ServiceBusHeaders}) onto the lease-based contract:
 *  - enqueue(): POST the canonical wire payload to /<queue>/messages.
 *  - fetch():   POST /<queue>/messages/head?timeout=<lock> (peek-lock). 201 holds the message
 *               under a LockToken; 204/anything-else means the queue is empty. The LockToken +
 *               MessageId form the lease token (".../<messageId>/<lockToken>") used to settle.
 *  - ack():     DELETE the lock location (complete/settle the message).
 *  - nack():    Service Bus has no native "unlock with delay", so we re-enqueue a fresh copy of
 *               the wire with attempts+1 (mirroring RedisBackend) — optionally scheduled via the
 *               ScheduledEnqueueTimeUtc broker property when a delay is requested — and then settle
 *               the original lock. Enqueue-first/settle-after means a failure never loses the message.
 *  - abandon(): settle (DELETE) the lock; the broker dead-letters natively after MaxDeliveryCount.
 *               The runtime forwards the payload to the configured DeadLetterQueue before calling this.
 *  - reapExpired(): 0 — the broker releases the lock automatically once it expires (LockedUntilUtc),
 *               so crash recovery happens server-side without a client-side reaper.
 *
 * Delivery is at-least-once; handlers should be idempotent.
 *
 * The raw HTTP calls go through an injected {@see ServiceBusTransport}; the default
 * {@see CurlServiceBusTransport} talks to the broker, while tests inject a fake that records calls
 * and serves stub responses — keeping the class {@see final} without needing a subclass.
 */
final class ServiceBusBackend implements QueueBackend
{
    private const BACKEND = 'servicebus';

    private readonly string $baseUrl;

    /**
     * @var array{url: string, issuer: string, secret: string}
     */
    private readonly array $credentials;

    private readonly ServiceBusTransport $transport;

    public function __construct(?ServiceBusTransport $transport = null)
    {
        $cfg = config('Jobs')->serviceBus;

        $url    = isset($cfg['url']) && is_string($cfg['url']) ? $cfg['url'] : '';
        $issuer = isset($cfg['issuer']) && is_string($cfg['issuer']) ? $cfg['issuer'] : '';
        $secret = isset($cfg['secret']) && is_string($cfg['secret']) ? $cfg['secret'] : '';

        if ($url === '' || $issuer === '' || $secret === '') {
            log_message('warning', 'ServiceBusBackend: incomplete configuration (url, issuer, or secret missing).');
        }

        $this->credentials = ['url' => $url, 'issuer' => $issuer, 'secret' => $secret];
        $this->baseUrl     = rtrim($url, '/') . '/';
        $this->transport   = $transport ?? new CurlServiceBusTransport();
    }

    public function enqueue(JobDefinition $definition): string
    {
        $queue      = $definition->queue ?? 'default';
        $identifier = bin2hex(random_bytes(8));
        $wire       = EnvelopeFactory::toWire($definition, $identifier);
        $body       = json_encode($wire, JSON_THROW_ON_ERROR);

        $this->postMessage($queue, $body, $definition->scheduledAt);

        return $identifier;
    }

    public function fetch(string $queue): ?JobLease
    {
        $lockTimeout = max(1, (int) (config('Jobs')->serviceBusLockTimeout ?? 60));

        $response = $this->transport->post(
            $this->baseUrl . $queue . '/messages/head?timeout=' . $lockTimeout,
            $this->authHeaders(),
        );

        $status = $this->statusOf($response);
        // 201 Created = peek-lock succeeded (message held). Anything else (204, 4xx, 5xx) = no usable message.
        if ($status !== 201) {
            return null;
        }

        [$messageId, $lockToken] = $this->extractLockTokens($response);
        if ($messageId === null || $lockToken === null) {
            log_message('error', 'ServiceBusBackend::fetch missing LockToken/MessageId in BrokerProperties; cannot ack message safely.');

            return null;
        }

        $rawBody = $this->bodyOf($response);
        $decoded = json_decode($rawBody);
        if (! $decoded instanceof stdClass) {
            // We hold the lock but cannot interpret the payload. Leave it locked: the broker
            // redelivers after lock expiry and dead-letters after MaxDeliveryCount.
            log_message('error', 'ServiceBusBackend::fetch deserialization failed; leaving message locked.');

            return null;
        }

        $lockLocation = $this->baseUrl . $queue . '/messages/' . rawurlencode($messageId) . '/' . rawurlencode($lockToken);

        $raw               = new stdClass();
        $raw->queue        = $queue;
        $raw->messageId    = $messageId;
        $raw->lockToken    = $lockToken;
        $raw->lockLocation = $lockLocation;
        $raw->wire         = $decoded;

        $envelope = new JobEnvelope(
            id: isset($decoded->identifier) && is_scalar($decoded->identifier) ? (string) $decoded->identifier : $messageId,
            queue: $queue,
            payload: $decoded,
            name: isset($decoded->name) && is_string($decoded->name) ? $decoded->name : null,
            attempts: isset($decoded->attempts) ? (int) $decoded->attempts : 0,
            priority: isset($decoded->priority) ? (int) $decoded->priority : null,
            meta: ['backend' => self::BACKEND, 'messageId' => $messageId, 'lockToken' => $lockToken, 'status' => $status],
            raw: $raw,
        );

        $owner = bin2hex(random_bytes(16));

        return JobLease::withRelativeExpiry($envelope, $lockLocation, $owner, $lockTimeout, self::BACKEND);
    }

    public function ack(JobLease $lease): bool
    {
        $location = $this->lockLocationOf($lease);
        if ($location === null) {
            return false;
        }

        $this->transport->delete($location, $this->authHeaders());

        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        $raw = $lease->envelope->raw;
        if (! $raw instanceof stdClass || ! isset($raw->wire) || ! $raw->wire instanceof stdClass) {
            return false;
        }

        $queue = isset($raw->queue) && is_string($raw->queue) ? $raw->queue : $lease->envelope->queue;

        // Re-serialise with attempts+1 so the incremented counter survives the requeue (the broker
        // has no in-place unlock-with-delay; we recreate the message exactly like RedisBackend).
        $wire           = clone $raw->wire;
        $wire->attempts = (isset($wire->attempts) ? (int) $wire->attempts : 0) + 1;
        $body           = json_encode($wire, JSON_THROW_ON_ERROR);

        $delay     = max(0, $delaySeconds ?? 0);
        $scheduled = $delay > 0
            ? (new DateTimeImmutable())->modify('+' . $delay . ' seconds')
            : null;

        // Enqueue the new copy FIRST so the message is never lost if the settle of the original fails.
        $this->postMessage($queue, $body, $scheduled);

        $location = $this->lockLocationOf($lease);
        if ($location !== null) {
            $this->transport->delete($location, $this->authHeaders());
        }

        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        // No client-side retry: settle the lock so the message is removed. Service Bus dead-letters
        // natively after MaxDeliveryCount; the runtime forwards to the configured DLQ before this call.
        $location = $this->lockLocationOf($lease);
        if ($location === null) {
            return false;
        }

        $this->transport->delete($location, $this->authHeaders());

        return true;
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        // The broker releases the peek-lock automatically once it expires (LockedUntilUtc), so a
        // client-side reaper is unnecessary for crash recovery.
        return 0;
    }

    /**
     * POST a serialized message body to the queue, optionally scheduled for future delivery.
     */
    private function postMessage(string $queue, string $body, ?DateTimeImmutable $scheduledAt): void
    {
        $builder = $this->newHeadersBuilder();
        if ($scheduledAt instanceof DateTimeImmutable) {
            try {
                $builder->schedule(DateTime::createFromInterface($scheduledAt)->setTimezone(new DateTimeZone('UTC')));
            } catch (Throwable $e) {
                log_message('warning', 'ServiceBusBackend::postMessage failed to set schedule: ' . $e->getMessage());
            }
        }

        $headers = array_merge(['Content-Type' => 'application/json'], $builder->getHeaders());

        $this->transport->post($this->baseUrl . $queue . '/messages', $headers, $body);
    }

    /**
     * Authorization-only headers for receive/settle (BrokerProperties carries the outgoing
     * MessageId and must not be sent on peek-lock or delete requests).
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $headers = $this->newHeadersBuilder()->getHeaders();
        unset($headers['BrokerProperties']);

        /** @var array<string, string> $headers */
        return $headers;
    }

    private function newHeadersBuilder(): ServiceBusHeaders
    {
        return (new ServiceBusHeaders())
            ->generateMessageId()
            ->generateSasToken($this->credentials['url'], $this->credentials['issuer'], $this->credentials['secret']);
    }

    /**
     * Pull MessageId and LockToken out of the BrokerProperties response header.
     *
     * @return array{0: ?string, 1: ?string} [messageId, lockToken]
     */
    private function extractLockTokens(object $response): array
    {
        if (! method_exists($response, 'getHeader')) {
            return [null, null];
        }

        $header = $response->getHeader('BrokerProperties');
        if ($header === null) {
            return [null, null];
        }

        $value = is_object($header) && method_exists($header, 'getValue')
            ? $header->getValue()
            : $header;

        if (! is_string($value) || $value === '') {
            return [null, null];
        }

        $decoded = json_decode($value);
        if (! $decoded instanceof stdClass) {
            return [null, null];
        }

        return [
            isset($decoded->MessageId) && is_scalar($decoded->MessageId) ? (string) $decoded->MessageId : null,
            isset($decoded->LockToken) && is_scalar($decoded->LockToken) ? (string) $decoded->LockToken : null,
        ];
    }

    private function lockLocationOf(JobLease $lease): ?string
    {
        $raw = $lease->envelope->raw;
        if ($raw instanceof stdClass && isset($raw->lockLocation) && is_string($raw->lockLocation) && $raw->lockLocation !== '') {
            return $raw->lockLocation;
        }

        return $lease->token !== '' ? $lease->token : null;
    }

    private function statusOf(object $response): int
    {
        if (! method_exists($response, 'getStatusCode')) {
            return 0;
        }

        return (int) $response->getStatusCode();
    }

    private function bodyOf(object $response): string
    {
        if (! method_exists($response, 'getBody')) {
            return '';
        }

        return (string) $response->getBody();
    }
}
