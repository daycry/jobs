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

namespace Tests\Unit\V3\Worker;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use DateTimeImmutable;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Execution\IdempotencyGuard;
use Daycry\Jobs\Execution\RetryPolicyFixed;
use Daycry\Jobs\Queues\EnvelopeFactory;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use Daycry\Jobs\Queues\Signing\EnvelopeSigner;
use Daycry\Jobs\Worker\QueueWorker;
use RuntimeException;
use stdClass;

/**
 * In-memory QueueBackend test double recording the terminal operation.
 *
 * @internal
 */
final class _FakeBackend implements QueueBackend
{
    public ?string $terminal = null;
    public ?int $nackDelay   = null;
    public int $fetches      = 0;

    public function __construct(private ?JobLease $next)
    {
    }

    public function enqueue(JobDefinition $definition): string
    {
        return 'fake-id';
    }

    public function fetch(string $queue): ?JobLease
    {
        $this->fetches++;
        $lease      = $this->next;
        $this->next = null;

        return $lease;
    }

    public function ack(JobLease $lease): bool
    {
        $this->terminal = 'ack';

        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        $this->terminal  = 'nack';
        $this->nackDelay = $delaySeconds;

        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        $this->terminal = 'abandon';

        return true;
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        return 0;
    }
}

/**
 * @internal
 */
final class QueueWorkerTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $fields
     */
    private function lease(array $fields, int $attempts): JobLease
    {
        $wire = new stdClass();

        foreach ($fields as $k => $v) {
            $wire->{$k} = $v;
        }

        $envelope = new JobEnvelope(id: 'id', queue: 'q', payload: $wire, attempts: $attempts, meta: []);

        return new JobLease($envelope, 'tok', 'owner', new DateTimeImmutable('+300 seconds'), 'fake');
    }

    private function unsignedWorker(_FakeBackend $backend, ?IdempotencyGuard $guard = null, ?RetryPolicyFixed $policy = null): QueueWorker
    {
        // Explicitly unconfigured signer => signature verification is skipped (deterministic).
        return new QueueWorker($backend, signer: new EnvelopeSigner(''), idempotency: $guard, retryPolicy: $policy);
    }

    public function testEmptyWhenNoJob(): void
    {
        $backend = new _FakeBackend(null);
        $result  = $this->unsignedWorker($backend)->processOnce('q');
        $this->assertSame('empty', $result->status);
    }

    public function testAcksOnSuccess(): void
    {
        $ran     = false;
        $backend = new _FakeBackend($this->lease([
            'job'     => 'closure',
            'payload' => static function () use (&$ran) {
                $ran = true;

                return 'ok';
            },
            'queue'      => 'q',
            'maxRetries' => 2,
        ], 0));

        $result = $this->unsignedWorker($backend)->processOnce('q');

        $this->assertTrue($ran);
        $this->assertSame('acked', $result->status);
        $this->assertSame('ack', $backend->terminal);
    }

    public function testRequeuesWithBackoffWhenRetriesRemain(): void
    {
        $backend = new _FakeBackend($this->lease([
            'job'        => 'closure',
            'payload'    => static function (): void { throw new RuntimeException('boom'); },
            'queue'      => 'q',
            'maxRetries' => 2,
        ], 0));

        // fixed strategy, base 5 => computeDelay(2) === 5
        $result = $this->unsignedWorker($backend, policy: new RetryPolicyFixed(base: 5, strategy: 'fixed'))->processOnce('q');

        $this->assertSame('requeued', $result->status);
        $this->assertSame('nack', $backend->terminal);
        $this->assertSame(5, $backend->nackDelay);
    }

    public function testDeadLettersWhenRetriesExhausted(): void
    {
        $backend = new _FakeBackend($this->lease([
            'job'        => 'closure',
            'payload'    => static function (): void { throw new RuntimeException('boom'); },
            'queue'      => 'q',
            'maxRetries' => 2,
        ], 2)); // already ran 3 times total (attempts 0,1,2)

        $result = $this->unsignedWorker($backend)->processOnce('q');

        $this->assertSame('dead-lettered', $result->status);
        $this->assertSame('abandon', $backend->terminal);
    }

    public function testRejectsInvalidSignature(): void
    {
        $ran     = false;
        $backend = new _FakeBackend($this->lease([
            'job'     => 'closure',
            'payload' => static function () use (&$ran) {
                $ran = true;

                return 'ok';
            },
            'queue'      => 'q',
            'maxRetries' => 0,
            '_sig'       => 'tampered',
        ], 0));

        // Configured signer + default verifyEnvelopeSignature=true => must reject.
        $worker = new QueueWorker($backend, signer: new EnvelopeSigner('secret-key'));
        $result = $worker->processOnce('q');

        $this->assertFalse($ran, 'handler must not run for an invalid signature');
        $this->assertSame('rejected', $result->status);
        $this->assertSame('abandon', $backend->terminal);
    }

    public function testProceedsOnValidSignature(): void
    {
        $signer = new EnvelopeSigner('secret-key');
        // Build a properly signed wire (command handler, JSON-serialisable payload).
        $wire  = EnvelopeFactory::toWire(new JobDefinition(handler: 'command', payload: 'jobs:test', queue: 'q'), 'id-1', $signer);
        $env   = new JobEnvelope(id: 'id-1', queue: 'q', payload: $wire, attempts: 0, meta: []);
        $lease = new JobLease($env, 'tok', 'owner', new DateTimeImmutable('+300 seconds'), 'fake');

        $backend = new _FakeBackend($lease);
        $result  = (new QueueWorker($backend, signer: $signer))->processOnce('q');

        // Valid signature => not rejected; command 'jobs:test' runs and succeeds.
        $this->assertSame('acked', $result->status);
        $this->assertSame('ack', $backend->terminal);
    }

    public function testSkipsDuplicateIdempotentMessage(): void
    {
        $cache = new MockCache();
        $cache->initialize();
        $guard = new IdempotencyGuard($cache);
        $guard->firstRun('dup-key'); // mark as already processed

        $ran     = false;
        $backend = new _FakeBackend($this->lease([
            'job'     => 'closure',
            'payload' => static function () use (&$ran) {
                $ran = true;

                return 'ok';
            },
            'queue'          => 'q',
            'maxRetries'     => 0,
            'idempotencyKey' => 'dup-key',
        ], 0));

        $result = $this->unsignedWorker($backend, guard: $guard)->processOnce('q');

        $this->assertFalse($ran, 'duplicate must not run');
        $this->assertSame('skipped-idempotent', $result->status);
        $this->assertSame('ack', $backend->terminal);
    }
}
