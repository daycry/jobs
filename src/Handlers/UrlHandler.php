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

namespace Daycry\Jobs\Handlers;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;

/**
 * Performs an HTTP request via the CodeIgniter curlrequest service.
 *
 * SSRF-hardened: scheme allowlist (http/https only), rejection of private/reserved
 * IPv4 and IPv6 targets (resolving A/AAAA records), SSL verification forced on, and
 * redirects disabled so a 3xx cannot bounce the request to an internal target.
 *
 * Residual risk: DNS rebinding (a different IP at cURL time than at validation time)
 * is not fully mitigated; document handlers that need it should pin resolution.
 *
 * Payload: ['method' => string, 'url' => string, 'options' => array(optional)].
 */
final class UrlHandler extends AbstractJobHandler
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload;
        $this->validate($payload);

        $options = $payload['options'] ?? [];
        // Prevent disabling SSL verification via options.
        unset($options['verify'], $options[CURLOPT_SSL_VERIFYPEER], $options[CURLOPT_SSL_VERIFYHOST]);
        // SSRF: never follow redirects (a 3xx could point at an internal host).
        $options['allow_redirects'] = false;

        return service('curlrequest')->request($payload['method'], $payload['url'], $options)->getBody();
    }

    private function validate(mixed $payload): void
    {
        if (! is_array($payload)) {
            throw JobException::validationError('UrlHandler payload must be an array with method and url keys.');
        }

        if (! isset($payload['url']) || ! is_string($payload['url']) || $payload['url'] === '') {
            throw JobException::validationError('UrlHandler payload must contain a valid url string.');
        }

        if (! isset($payload['method']) || ! is_string($payload['method']) || $payload['method'] === '') {
            throw JobException::validationError('UrlHandler payload must contain a valid method string.');
        }

        $method = strtoupper($payload['method']);
        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            throw JobException::forInvalidMethod($method);
        }

        if (filter_var($payload['url'], FILTER_VALIDATE_URL) === false) {
            throw JobException::validationError('UrlHandler payload contains an invalid URL.');
        }

        $this->blockInternalUrls($payload['url']);
    }

    /**
     * Enforce scheme allowlist and reject internal/private/reserved targets.
     */
    private function blockInternalUrls(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw JobException::validationError('UrlHandler could not parse URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw JobException::validationError("UrlHandler scheme '{$scheme}' is not allowed (only http/https).");
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            throw JobException::validationError('UrlHandler could not parse host from URL.');
        }

        // IPv6 literal hosts arrive bracketed per RFC 3986: http://[::1]/
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            $this->validatePublicIp($literal);

            return;
        }

        // Hostname: resolve all A and AAAA records and reject if ANY points internal.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records) || $records === []) {
            $ip = gethostbyname($host);
            if ($ip === $host || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                throw JobException::validationError("UrlHandler could not resolve host '{$host}'.");
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

    private function validatePublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw JobException::validationError("UrlHandler does not allow requests to internal IP '{$ip}'.");
        }
    }
}
