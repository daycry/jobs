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

namespace Tests\Unit\V1_1;

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Jobs\UrlJob;
use Tests\Support\TestCase;
use Throwable;

/**
 * Hardening tests added in v1.1: explicit scheme whitelist, IPv6 literal handling,
 * multi-IP DNS resolution. The existing UrlJobTest still covers IPv4-only flows.
 *
 * @internal
 */
final class UrlJobHardeningTest extends TestCase
{
    public function testRejectsFileScheme(): void
    {
        $this->expectException(JobException::class);

        (new UrlJob())->handle([
            'url'    => 'file:///etc/passwd',
            'method' => 'GET',
        ]);
    }

    public function testRejectsGopherScheme(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage("scheme 'gopher'");

        (new UrlJob())->handle([
            'url'    => 'gopher://internal.local/',
            'method' => 'GET',
        ]);
    }

    public function testRejectsIpv6Localhost(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('internal IP');

        (new UrlJob())->handle([
            'url'    => 'http://[::1]/admin',
            'method' => 'GET',
        ]);
    }

    public function testRejectsIpv6PrivateRange(): void
    {
        // fc00::/7 is the IPv6 Unique Local Address range — must be treated as internal.
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('internal IP');

        (new UrlJob())->handle([
            'url'    => 'http://[fc00::1]/',
            'method' => 'GET',
        ]);
    }

    public function testRejectsIpv4LiteralPrivate(): void
    {
        $this->expectException(JobException::class);

        (new UrlJob())->handle([
            'url'    => 'http://10.0.0.5/internal',
            'method' => 'GET',
        ]);
    }

    public function testAcceptsIpv6PublicLiteral(): void
    {
        // 2606:4700:4700::1111 is one of Cloudflare's public DNS addresses (1.1.1.1 v6 sibling).
        // We invoke validation only — handle() will try to perform the request which may fail in
        // CI with no network; the assertion is solely that scheme + IP validation does not throw.
        $job = new UrlJob();

        try {
            $job->handle([
                'url'    => 'http://[2606:4700:4700::1111]/',
                'method' => 'GET',
            ]);
        } catch (JobException $e) {
            // Validation should NOT have produced this; if it did, fail the test.
            if (str_contains($e->getMessage(), 'internal IP')
                || str_contains($e->getMessage(), 'scheme ')
                || str_contains($e->getMessage(), 'could not parse')
                || str_contains($e->getMessage(), 'could not resolve')) {
                $this->fail('Validation rejected a public IPv6 literal: ' . $e->getMessage());
            }
        } catch (Throwable) {
            // Any other exception (e.g. cURL connectivity) is not the contract we test here.
        }

        $this->addToAssertionCount(1);
    }
}
