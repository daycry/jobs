<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Handlers;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobContext;
use Daycry\Jobs\Handlers\UrlHandler;

/**
 * @internal
 */
final class UrlHandlerTest extends CIUnitTestCase
{
    public function testRejectsNonArrayPayload(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload('https://x'));
    }

    public function testRejectsDisallowedScheme(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload(['method' => 'GET', 'url' => 'file:///etc/passwd']));
    }

    public function testRejectsInternalHost(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload(['method' => 'GET', 'url' => 'http://127.0.0.1/']));
    }

    public function testRejectsInvalidMethod(): void
    {
        $this->expectException(JobException::class);
        (new UrlHandler())->handle(JobContext::fromPayload(['method' => 'CONNECT', 'url' => 'https://example.com/']));
    }
}
