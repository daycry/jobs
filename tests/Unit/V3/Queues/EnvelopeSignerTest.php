<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;

/**
 * @internal
 */
final class EnvelopeSignerTest extends CIUnitTestCase
{
    public function testSignAndVerifyWithExplicitKey(): void
    {
        $signer = new EnvelopeSigner('secret-key');
        $this->assertTrue($signer->isConfigured());

        $signature = $signer->sign('{"a":1}');
        $this->assertNotSame('', $signature);
        $this->assertTrue($signer->verify('{"a":1}', $signature));
    }

    public function testVerifyRejectsBadSignature(): void
    {
        $signer = new EnvelopeSigner('secret-key');
        $this->assertFalse($signer->verify('{"a":1}', 'bad'));
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $signer    = new EnvelopeSigner('secret-key');
        $signature = $signer->sign('{"a":1}');
        $this->assertFalse($signer->verify('{"a":2}', $signature));
    }

    public function testVerifyRejectsNullSignature(): void
    {
        $signer = new EnvelopeSigner('secret-key');
        $this->assertFalse($signer->verify('{"a":1}', null));
    }

    public function testEmptyExplicitKeyIsNotConfigured(): void
    {
        // An empty string is an explicit "no key" and must not silently fall back.
        $signer = new EnvelopeSigner('');
        $this->assertFalse($signer->isConfigured());
        $this->assertSame('', $signer->sign('{"a":1}'));
        // Insecure pass-through mode: verification always succeeds.
        $this->assertTrue($signer->verify('{"a":1}', null));
        $this->assertTrue($signer->verify('{"a":1}', 'whatever'));
    }
}
