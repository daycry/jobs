<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;
use Daycry\Jobs\V2\JobDefinition;

/**
 * @internal
 */
final class EnvelopeFactoryTest extends CIUnitTestCase
{
    public function testToWireBuildsCanonicalShape(): void
    {
        $def  = new JobDefinition(handler: 'command', payload: ['x' => 1], queue: 'q', priority: 3, maxRetries: 5);
        $wire = EnvelopeFactory::toWire($def, 'id-xyz');

        $this->assertSame('command', $wire->job);
        $this->assertSame(['x' => 1], $wire->payload);
        $this->assertSame('q', $wire->queue);
        $this->assertSame(3, $wire->priority);
        $this->assertSame(5, $wire->maxRetries);
        $this->assertSame(0, $wire->attempts);
        $this->assertSame('id-xyz', $wire->identifier);
        $this->assertNull($wire->schedule);
    }

    public function testToWireDefaultsQueueToDefault(): void
    {
        $def  = new JobDefinition(handler: 'shell', payload: 'echo hi');
        $wire = EnvelopeFactory::toWire($def, 'id-1');
        $this->assertSame('default', $wire->queue);
    }

    public function testToWireSignsImmutableFields(): void
    {
        $signer = new EnvelopeSigner('secret-key');
        $def    = new JobDefinition(handler: 'command', payload: ['x' => 1], queue: 'q');
        $wire   = EnvelopeFactory::toWire($def, 'id-xyz', $signer);

        $this->assertNotSame('', $wire->_sig);
        $this->assertTrue($signer->verify(EnvelopeFactory::canonicalJson($wire), $wire->_sig));
    }

    public function testSignatureSurvivesAttemptsMutation(): void
    {
        // attempts is excluded from the signature, so a requeue (attempts++) must not break it.
        $signer = new EnvelopeSigner('secret-key');
        $wire   = EnvelopeFactory::toWire(new JobDefinition(handler: 'command', payload: null), 'id-1', $signer);

        $wire->attempts = 5; // simulate a Redis requeue re-serialisation
        $this->assertTrue($signer->verify(EnvelopeFactory::canonicalJson($wire), $wire->_sig));
    }

    public function testTamperedIdentityFieldFailsVerification(): void
    {
        $signer = new EnvelopeSigner('secret-key');
        $wire   = EnvelopeFactory::toWire(new JobDefinition(handler: 'command', payload: null), 'id-1', $signer);

        $wire->job = 'shell'; // attacker swaps the handler
        $this->assertFalse($signer->verify(EnvelopeFactory::canonicalJson($wire), $wire->_sig));
    }
}
