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
    private const ALLOWED_SCHEMES = ['http', 'https'];

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
     * Prevent Server-Side Request Forgery (SSRF) by enforcing scheme whitelist
     * and rejecting requests targeting internal/private IPv4 or IPv6 addresses.
     *
     * Note: this validates resolved IPs at enqueue/execute time. DNS rebinding
     * (different IP at cURL time) is documented as a residual risk; full mitigation
     * requires CURLOPT_RESOLVE which is not exposed by the CI4 curlrequest wrapper.
     */
    private function blockInternalUrls(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw JobException::validationError('UrlJob could not parse URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw JobException::validationError("UrlJob scheme '{$scheme}' is not allowed (only http/https).");
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            throw JobException::validationError('UrlJob could not parse host from URL.');
        }

        // IPv6 literal hosts arrive enclosed in brackets per RFC 3986: http://[::1]/
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            $this->validatePublicIp($literal);

            return;
        }

        // Hostname: resolve all A and AAAA records and reject if ANY points internal.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records) || $records === []) {
            // Fallback to gethostbyname (returns input on failure) for edge cases
            // where dns_get_record fails (e.g. local resolver issues).
            $ip = gethostbyname($host);
            if ($ip === $host || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                throw JobException::validationError("UrlJob could not resolve host '{$host}'.");
            }
            $this->validatePublicIp($ip);

            return;
        }

        foreach ($records as $rec) {
            $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $this->validatePublicIp($ip);
            }
        }
    }

    /**
     * Validate that an IP is not in a private or reserved range (IPv4 or IPv6).
     */
    private function validatePublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw JobException::validationError("UrlJob does not allow requests to internal IP '{$ip}'.");
        }
    }
}
