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
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;

/**
 * @internal
 */
final class CovEnvelopeSignerTest extends CIUnitTestCase
{
    public function testConfiguredSignerSignsAndVerifies(): void
    {
        $signer = new EnvelopeSigner('cov-secret');
        $this->assertTrue($signer->isConfigured());

        $sig = $signer->sign('{"x":1}');
        $this->assertNotSame('', $sig);
        $this->assertSame(hash_hmac('sha256', '{"x":1}', 'cov-secret'), $sig);
        $this->assertTrue($signer->verify('{"x":1}', $sig));
    }

    public function testConfiguredSignerRejectsTamperedPayload(): void
    {
        $signer = new EnvelopeSigner('cov-secret');
        $sig    = $signer->sign('{"x":1}');
        $this->assertFalse($signer->verify('{"x":2}', $sig));
    }

    public function testConfiguredSignerRejectsNullAndEmptySignature(): void
    {
        $signer = new EnvelopeSigner('cov-secret');
        $this->assertFalse($signer->verify('{"x":1}', null));
        $this->assertFalse($signer->verify('{"x":1}', ''));
    }

    public function testEmptyExplicitKeyIsUnconfiguredAndPassThrough(): void
    {
        // Explicit '' short-circuits the resolution chain -> unconfigured / insecure mode.
        $signer = new EnvelopeSigner('');
        $this->assertFalse($signer->isConfigured());
        $this->assertSame('', $signer->sign('{"x":1}'));
        $this->assertTrue($signer->verify('{"x":1}', null));
        $this->assertTrue($signer->verify('{"x":1}', 'anything'));
    }

    public function testResolvesKeyFromJobsConfig(): void
    {
        $cfg             = config(Jobs::class) ?? new Jobs();
        $cfg->signingKey = 'from-config-key';

        $signer = new EnvelopeSigner();
        $this->assertTrue($signer->isConfigured());
        $this->assertSame(
            hash_hmac('sha256', '{"x":1}', 'from-config-key'),
            $signer->sign('{"x":1}'),
        );
    }
}
