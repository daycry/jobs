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

namespace Daycry\Jobs\Jobs;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Traits\InteractsWithCurrentJob;

/**
 * Performs an HTTP request using CodeIgniter curlrequest service.
 * Payload: ['method' => 'GET'|'POST'|..., 'url' => string, 'options' => array(optional)].
 * Returns raw response body string.
 */
class UrlJob extends Job implements JobInterface
{
    use InteractsWithCurrentJob;

    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public function handle(mixed $payload): mixed
    {
        $this->validatePayload($payload);

        $options = $payload['options'] ?? [];
        // Prevent disabling SSL verification via options
        unset($options['verify'], $options[CURLOPT_SSL_VERIFYPEER], $options[CURLOPT_SSL_VERIFYHOST]);

        return service('curlrequest')->request($payload['method'], $payload['url'], $options)->getBody();
    }

    private function validatePayload(mixed $payload): void
    {
        if (! is_array($payload)) {
            throw JobException::validationError('UrlJob payload must be an array with method and url keys.');
        }

        if (empty($payload['url']) || ! is_string($payload['url'])) {
            throw JobException::validationError('UrlJob payload must contain a valid url string.');
        }

        if (empty($payload['method']) || ! is_string($payload['method'])) {
            throw JobException::validationError('UrlJob payload must contain a valid method string.');
        }

        $method = strtoupper($payload['method']);
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw JobException::forInvalidMethod($method);
        }

        $url = $payload['url'];
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw JobException::validationError('UrlJob payload contains an invalid URL.');
        }

        $this->blockInternalUrls($url);
    }

    /**
     * Prevent Server-Side Request Forgery (SSRF) by blocking requests to internal/private IPs.
     */
    private function blockInternalUrls(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            throw JobException::validationError('UrlJob could not parse host from URL.');
        }

        // Resolve hostname to IP (catches DNS rebinding for common cases)
        $ip = gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw JobException::validationError('UrlJob does not allow requests to private or reserved IP ranges.');
        }
    }
}
