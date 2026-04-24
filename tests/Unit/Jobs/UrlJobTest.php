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

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Jobs\UrlJob;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class UrlJobTest extends TestCase
{
    private function makeHandler(): UrlJob
    {
        return new UrlJob();
    }

    public function testHandleThrowsOnNonArrayPayload(): void
    {
        $this->expectException(JobException::class);
        $this->makeHandler()->handle('not-an-array');
    }

    public function testHandleThrowsOnMissingUrl(): void
    {
        $this->expectException(JobException::class);
        $this->makeHandler()->handle(['method' => 'GET']);
    }

    public function testHandleThrowsOnInvalidUrl(): void
    {
        $this->expectException(JobException::class);
        $this->makeHandler()->handle(['method' => 'GET', 'url' => 'not-a-valid-url']);
    }

    public function testHandleThrowsOnMissingMethod(): void
    {
        $this->expectException(JobException::class);
        $this->makeHandler()->handle(['url' => 'https://example.com']);
    }

    public function testHandleThrowsOnInvalidMethod(): void
    {
        $this->expectException(JobException::class);
        $this->makeHandler()->handle(['method' => 'BADMETHOD', 'url' => 'https://example.com']);
    }

    public function testHandleThrowsOnInternalIpUrl(): void
    {
        $this->expectException(JobException::class);
        // 127.0.0.1 is a loopback/private address
        $this->makeHandler()->handle(['method' => 'GET', 'url' => 'http://127.0.0.1/admin']);
    }

    public function testHandleThrowsOnPrivateRangeIp(): void
    {
        $this->expectException(JobException::class);
        // 192.168.x.x is private range
        $this->makeHandler()->handle(['method' => 'GET', 'url' => 'http://192.168.1.1/']);
    }
}
