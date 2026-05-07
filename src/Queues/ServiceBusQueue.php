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

namespace Daycry\Jobs\Queues;

use DateTimeInterface;
use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as QueuesJob;
use Daycry\Jobs\Libraries\ServiceBusHeaders;
use Throwable;

/**
 * Azure Service Bus queue (simplified HTTP interface using CodeIgniter curlrequest).
 *
 * v1.1+ uses **peek-lock** semantics so a worker crash does not lose messages:
 *  - watch(): POST /<queue>/messages/head?timeout=<lockTimeout> → 201 Created with
 *             BrokerProperties header containing LockToken + MessageId. Body is the payload.
 *  - removeJob(recreate=false): DELETE /<queue>/messages/<MessageId>/<LockToken> (settle).
 *  - removeJob(recreate=true):  enqueue a fresh copy first, then settle the original
 *                                so the message is never lost between calls.
 *
 * If a worker dies before settling the lock, Azure Service Bus will redeliver the
 * message to another worker once the lock expires (LockedUntilUtc), giving us
 * crash-recovery semantics for free at the broker layer.
 *
 * Limitations:
 *  - SAS token expires (~1 week per generation); long-running workers may need to
 *    rebuild the headers builder. Documented; full lifecycle handling deferred to v2.0.
 *  - Lock renewal for very long jobs is not implemented; configure
 *    `serviceBusLockTimeout` >= the maximum expected job runtime.
 */
class ServiceBusQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    private readonly string $baseUrl; // ['issuer' => '', 'secret' => '']

    /**
     * In-flight lease for the currently watched message. Keys:
     *  - lockToken: string|null
     *  - messageId: string|null
     *  - queue:     string
     *  - body:      object decoded payload
     *
     * @var array<string, mixed>|null
     */
    private ?array $job = null;

    private readonly ServiceBusHeaders $headersBuilder;

    public function __construct()
    {
        $cfg = config('Jobs')->serviceBus ?? [
            'url'    => getenv('SERVICEBUS_URL') ?: '',
            'issuer' => getenv('SERVICEBUS_ISSUER') ?: '',
            'secret' => getenv('SERVICEBUS_SECRET') ?: '',
        ];

        if (empty($cfg['url']) || empty($cfg['issuer']) || empty($cfg['secret'])) {
            log_message('warning', 'ServiceBusQueue: incomplete configuration (url, issuer, or secret missing).');
        }

        $this->baseUrl        = rtrim($cfg['url'] ?? '', '/') . '/';
        $this->headersBuilder = (new ServiceBusHeaders())
            ->generateMessageId()
            ->generateSasToken($cfg['url'] ?? '', $cfg['issuer'] ?? '', $cfg['secret'] ?? '');
    }

    public function enqueue(object $data): string
    {
        $queue = $data->queue ?? 'default';
        $delay = $this->calculateDelay($data);
        // BrokerProperties via ServiceBusHeaders
        if (! $delay->isImmediate() && $delay->scheduledAt instanceof DateTimeInterface) {
            // schedule->getTimestamp ya es DateTime; usar schedule builder
            try {
                $this->headersBuilder->schedule($delay->scheduledAt);
            } catch (Throwable) { // ignore
            }
        }
        if (isset($data->label)) {
            try {
                $this->headersBuilder->setLabel((string) $data->label);
            } catch (Throwable) { // ignore
            }
        }
        $headers = array_merge(['Content-Type' => 'application/json'], $this->headersBuilder->getHeaders());
        $resp    = $this->client()->post($this->baseUrl . $queue . '/messages', [
            'headers' => $headers,
            'body'    => $this->getSerializer()->serialize($data),
        ]);

        return ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300)
            ? $this->headersBuilder->getMessageId()
            : '';
    }

    public function watch(string $queue): mixed
    {
        $cfg         = config('Jobs');
        $lockTimeout = max(1, (int) ($cfg->serviceBusLockTimeout ?? 60));

        $headers = array_merge(['Content-Type' => 'application/json'], $this->headersBuilder->getHeaders());
        // Authorization is the only header required for the peek-lock POST; strip BrokerProperties
        // (which carries the *outgoing* MessageId) so the broker does not reject the request.
        unset($headers['BrokerProperties']);

        $resp = $this->client()->post(
            $this->baseUrl . $queue . '/messages/head?timeout=' . $lockTimeout,
            ['headers' => $headers],
        );

        if (! method_exists($resp, 'getStatusCode')) {
            return null;
        }

        $status = (int) $resp->getStatusCode();
        // 201 Created = peek-lock succeeded (message held), 204 No Content = empty queue.
        if ($status !== 201) {
            return null;
        }

        [$messageId, $lockToken] = $this->extractLockTokens($resp);

        if ($lockToken === null || $messageId === null) {
            log_message('error', 'ServiceBusQueue::watch missing LockToken/MessageId in BrokerProperties; cannot ack message safely.');

            return null;
        }

        $rawBody = (string) $resp->getBody();
        $body    = $this->getSerializer()->deserialize($rawBody);
        if (! $body) {
            // We hold the lock but cannot interpret the payload. Don't ack:
            // the broker will redeliver after lock expiry and eventually
            // dead-letter the message after MaxDeliveryCount.
            log_message('error', 'ServiceBusQueue::watch deserialization failed; leaving message locked. Raw body: ' . mb_substr($rawBody, 0, 500));

            return null;
        }

        $this->job = [
            'lockToken' => $lockToken,
            'messageId' => $messageId,
            'queue'     => $queue,
            'body'      => $body,
        ];

        return JobEnvelope::fromBackend(
            backend: 'servicebus',
            id: $messageId,
            queue: $queue,
            payload: $body,
            extraMeta: ['lockToken' => $lockToken, 'status' => $status],
            raw: $this->job,
        );
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        if ($this->job === null) {
            // Legacy call path (no active lease): keep the previous behaviour so
            // callers that just push a recreate without a prior watch() still work.
            if ($recreate) {
                $this->enqueue($job->toObject());
            }

            return true;
        }

        $queue     = (string) ($this->job['queue'] ?? 'default');
        $messageId = (string) ($this->job['messageId'] ?? '');
        $lockToken = (string) ($this->job['lockToken'] ?? '');

        if ($messageId === '' || $lockToken === '') {
            $this->job = null;

            return false;
        }

        if ($recreate) {
            // Enqueue the new copy FIRST so we never lose the message if the
            // settle of the original fails. If enqueue throws, the broker will
            // redeliver the original after lock expiry — no data loss.
            $this->enqueue($job->toObject());
        }

        $this->settleLockedMessage($queue, $messageId, $lockToken);
        $this->job = null;

        return true;
    }

    /**
     * Pull MessageId and LockToken out of the BrokerProperties response header.
     *
     * @return array{0: ?string, 1: ?string} [messageId, lockToken]
     */
    private function extractLockTokens(object $resp): array
    {
        if (! method_exists($resp, 'getHeader')) {
            return [null, null];
        }

        $header = $resp->getHeader('BrokerProperties');
        if ($header === null) {
            return [null, null];
        }

        $value = method_exists($header, 'getValue') ? $header->getValue() : (string) $header;
        if (! is_string($value) || $value === '') {
            return [null, null];
        }

        $decoded = json_decode($value);
        if (! is_object($decoded)) {
            return [null, null];
        }

        return [
            isset($decoded->MessageId) ? (string) $decoded->MessageId : null,
            isset($decoded->LockToken) ? (string) $decoded->LockToken : null,
        ];
    }

    /**
     * Settle (DELETE) the locked message. A failure here only triggers a warning;
     * the broker will redeliver after lock expiry, so no data is lost.
     */
    private function settleLockedMessage(string $queue, string $messageId, string $lockToken): void
    {
        try {
            $headers = $this->headersBuilder->getHeaders();
            unset($headers['BrokerProperties']);

            $this->client()->delete(
                $this->baseUrl . $queue . '/messages/' . rawurlencode($messageId) . '/' . rawurlencode($lockToken),
                ['headers' => $headers],
            );
        } catch (Throwable $e) {
            log_message('warning', "ServiceBusQueue::settleLockedMessage failed for messageId={$messageId}: " . $e->getMessage() . '. Broker will redeliver after lock expiry.');
        }
    }

    /**
     * HTTP client accessor (overridable for tests).
     * Returns an object implementing post(string $url, array $options = []) and
     * delete(string $url, array $options = []) with ->getStatusCode() / ->getBody().
     */
    protected function client(): object
    {
        // Servicio por defecto; opciones globales deberían configurarse en Config\CURLRequest si se requiere.
        return service('curlrequest');
    }

    // buildHeaders eliminado; ahora se usa ServiceBusHeaders
}
