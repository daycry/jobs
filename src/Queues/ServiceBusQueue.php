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

use Daycry\Jobs\Interfaces\QueueInterface;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job as QueuesJob;
use Daycry\Jobs\Libraries\ServiceBusHeaders;
use Throwable;

/**
 * Azure Service Bus queue (simplified HTTP interface using CodeIgniter curlrequest).
 *
 * Contract notes:
 *  - enqueue(): sends a message, returns generated MessageId string or empty string on failure.
 *  - watch(): retrieves (and deletes) the head message (destructive read) and returns JobEnvelope or null.
 *  - removeJob(): optionally re-enqueues the original Job (no lock renewal / settlement implemented).
 *
 * Limitations / TODO:
 *  - Real SAS token lifetime / caching not implemented (token built per instance).
 *  - Proper peek-lock flow (receive + settle) not implemented; current watch() is destructive (DELETE head).
 *  - Scheduling uses x-ms-scheduled-enqueue-time only if provided.
 */
class ServiceBusQueue extends BaseQueue implements QueueInterface, WorkerInterface
{
    private string $baseUrl;
    private array $auth; // ['issuer' => '', 'secret' => '']
    private ?array $job = null; // ['body' => object, 'headers' => [], 'status' => int]
    private ServiceBusHeaders $headersBuilder;

    public function __construct()
    {
        $cfg = config('Jobs')->serviceBus ?? [
            'url'    => getenv('SERVICEBUS_URL') ?: '',
            'issuer' => getenv('SERVICEBUS_ISSUER') ?: '',
            'secret' => getenv('SERVICEBUS_SECRET') ?: '',
        ];
        $this->baseUrl        = rtrim($cfg['url'] ?? '', '/') . '/';
        $this->auth           = $cfg;
        $this->headersBuilder = (new ServiceBusHeaders())
            ->generateMessageId()
            ->generateSasToken($cfg['url'] ?? '', $cfg['issuer'] ?? '', $cfg['secret'] ?? '');
    }

    public function enqueue(object $data): string
    {
        $queue = $data->queue ?? 'default';
        $delay = $this->calculateDelay($data);
        // BrokerProperties via ServiceBusHeaders
        if (! $delay->isImmediate() && $delay->scheduledAt !== null) {
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

    public function watch(string $queue)
    {
        $resp = $this->client()->delete($this->baseUrl . $queue . '/messages/head', [
            'headers' => array_merge(['Content-Type' => 'application/json'], $this->headersBuilder->getHeaders()),
        ]);
        if (method_exists($resp, 'getStatusCode') && $resp->getStatusCode() === 200) {
            $body = $this->getSerializer()->deserialize((string) $resp->getBody());
            if (! $body) {
                return null;
            }
            $this->job = ['body' => $body, 'status' => 200, 'headers' => []];

            return JobEnvelope::fromBackend(
                backend: 'servicebus',
                id: $this->headersBuilder->getMessageId(),
                queue: $queue,
                payload: $body,
                extraMeta: ['status' => 200],
                raw: $this->job,
            );
        }

        return null;
    }

    public function removeJob(QueuesJob $job, bool $recreate = false): bool
    {
        if ($recreate) {
            // Re-enqueue directly to this service bus backend instead of using enqueue()
            // which might cause issues with queue configuration
            $this->enqueue($job->toObject());
        }
        $this->job = null;

        return true;
    }

    /**
     * HTTP client accessor (overridable for tests).
     * Returns an object implementing post(string $url, array $options = []) and
     * delete(string $url, array $options = []) with ->getStatusCode() / ->getBody().
     *
     * @return object
     */
    protected function client()
    {
        // Servicio por defecto; opciones globales deberían configurarse en Config\CURLRequest si se requiere.
        return service('curlrequest');
    }

    // buildHeaders eliminado; ahora se usa ServiceBusHeaders
}
