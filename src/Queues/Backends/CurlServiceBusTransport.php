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
 * Default {@see ServiceBusTransport} forwarding to CodeIgniter's curlrequest service. The HTTP
 * response it returns already exposes getStatusCode()/getBody()/getHeader(), matching the shape
 * {@see ServiceBusBackend} expects.
 */
final class CurlServiceBusTransport implements ServiceBusTransport
{
    public function post(string $url, array $headers, ?string $body = null): object
    {
        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['body'] = $body;
        }

        return service('curlrequest')->post($url, $options);
    }

    public function delete(string $url, array $headers): object
    {
        return service('curlrequest')->delete($url, ['headers' => $headers]);
    }
}
