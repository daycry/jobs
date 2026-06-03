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

/**
 * Thin HTTP seam for {@see ServiceBusBackend} so the backend can stay {@see final} while still
 * being unit-testable without a real Azure Service Bus namespace.
 *
 * Each method returns a response object exposing (at minimum) getStatusCode(): int and
 * getBody(): string, plus getHeader(string): mixed for {@see post()} when peek-locking. This is
 * exactly the shape produced by CodeIgniter's curlrequest service, so the default
 * {@see CurlServiceBusTransport} simply forwards to it.
 */
interface ServiceBusTransport
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers, ?string $body = null): object;

    /**
     * @param array<string, string> $headers
     */
    public function delete(string $url, array $headers): object;
}
