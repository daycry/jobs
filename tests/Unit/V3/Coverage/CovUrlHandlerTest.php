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
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\UrlHandler;

/**
 * Exercises only the validation / SSRF-blocking branches of UrlHandler that throw
 * before any network request would be issued. No real HTTP traffic is generated.
 *
 * @internal
 */
final class CovUrlHandlerTest extends CIUnitTestCase
{
    private function handle(mixed $payload): void
    {
        (new UrlHandler())->handle(JobContext::fromPayload($payload));
    }

    public function testRejectsNonArrayPayload(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('must be an array');
        $this->handle('https://example.com/');
    }

    public function testRejectsMissingUrl(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('valid url string');
        $this->handle(['method' => 'GET']);
    }

    public function testRejectsEmptyUrl(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('valid url string');
        $this->handle(['method' => 'GET', 'url' => '']);
    }

    public function testRejectsNonStringUrl(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('valid url string');
        $this->handle(['method' => 'GET', 'url' => 123]);
    }

    public function testRejectsMissingMethod(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('valid method string');
        $this->handle(['url' => 'https://example.com/']);
    }

    public function testRejectsEmptyMethod(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('valid method string');
        $this->handle(['method' => '', 'url' => 'https://example.com/']);
    }

    public function testRejectsNonStringMethod(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('valid method string');
        $this->handle(['method' => 5, 'url' => 'https://example.com/']);
    }

    public function testRejectsDisallowedMethod(): void
    {
        $this->expectException(JobException::class);
        $this->handle(['method' => 'CONNECT', 'url' => 'https://example.com/']);
    }

    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('invalid URL');
        // FILTER_VALIDATE_URL fails on a scheme-less, space-bearing string.
        $this->handle(['method' => 'GET', 'url' => 'http://']);
    }

    public function testRejectsNonHttpScheme(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('is not allowed');
        $this->handle(['method' => 'GET', 'url' => 'ftp://example.com/resource']);
    }

    public function testRejectsFileScheme(): void
    {
        $this->expectException(JobException::class);
        $this->handle(['method' => 'GET', 'url' => 'file:///etc/passwd']);
    }

    public function testRejectsPrivateIpv4Literal(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('internal IP');
        $this->handle(['method' => 'GET', 'url' => 'http://127.0.0.1/']);
    }

    public function testRejectsLoopbackIpv6Literal(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('internal IP');
        $this->handle(['method' => 'GET', 'url' => 'http://[::1]/']);
    }

    public function testRejectsPrivateRangeIpv4Literal(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('internal IP');
        $this->handle(['method' => 'GET', 'url' => 'http://10.0.0.5/admin']);
    }

    public function testRejectsHostThatDoesNotResolve(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('could not resolve host');
        // The reserved .invalid TLD (RFC 6761) is guaranteed never to resolve, so this
        // exercises the resolution-failure branch without contacting any real server.
        $this->handle(['method' => 'GET', 'url' => 'http://nonexistent.invalid/']);
    }
}
