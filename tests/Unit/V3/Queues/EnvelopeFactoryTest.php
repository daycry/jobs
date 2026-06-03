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

namespace Tests\Unit\V3\Queues;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;

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

    public function testToWirePropagatesIdempotencyKey(): void
    {
        // Regression: the definition's idempotencyKey must be serialised onto the wire so the
        // worker's IdempotencyGuard can see it. Previously toWire() dropped it (guard was inert).
        $def  = new JobDefinition(handler: 'command', payload: null, idempotencyKey: 'rebuild-index');
        $wire = EnvelopeFactory::toWire($def, 'id-1');

        $this->assertSame('rebuild-index', $wire->idempotencyKey);
    }

    public function testToWireIdempotencyKeyDefaultsToNull(): void
    {
        $wire = EnvelopeFactory::toWire(new JobDefinition(handler: 'command', payload: null), 'id-1');

        $this->assertNull($wire->idempotencyKey);
    }

    public function testIdempotencyKeyIsPartOfSignature(): void
    {
        // The key is an immutable identity field: tampering with it (e.g. swapping in a key known
        // to be already-processed, to suppress a legitimate run) must fail verification.
        $signer = new EnvelopeSigner('secret-key');
        $def    = new JobDefinition(handler: 'command', payload: null, idempotencyKey: 'k1');
        $wire   = EnvelopeFactory::toWire($def, 'id-1', $signer);

        $this->assertTrue($signer->verify(EnvelopeFactory::canonicalJson($wire), $wire->_sig));

        $wire->idempotencyKey = 'k2';
        $this->assertFalse($signer->verify(EnvelopeFactory::canonicalJson($wire), $wire->_sig));
    }
}
