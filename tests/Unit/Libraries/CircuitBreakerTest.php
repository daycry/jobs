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

namespace Tests\Unit\Libraries;

use Daycry\Jobs\Libraries\CircuitBreaker;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Start with a clean circuit breaker state for each test
        (new CircuitBreaker('test', 3, 10))->reset();
    }

    public function testInitialStateIsClosed(): void
    {
        $cb = new CircuitBreaker('test', 3, 10);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());
    }

    public function testGetFailureCountStartsAtZero(): void
    {
        $cb = new CircuitBreaker('test', 3, 10);
        $this->assertSame(0, $cb->getFailureCount());
    }

    public function testRecordSuccessKeepsClosed(): void
    {
        $cb = new CircuitBreaker('test', 3, 10);
        $cb->recordSuccess();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());
    }

    public function testRecordFailuresIncreasesCount(): void
    {
        $cb = new CircuitBreaker('test', 3, 10);
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(2, $cb->getFailureCount());
        // Still closed — below threshold
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());
    }

    public function testCircuitTripsWhenThresholdReached(): void
    {
        $cb = new CircuitBreaker('test', 3, 10);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure(); // hits threshold
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
        $this->assertFalse($cb->isAvailable());
    }

    public function testRecordSuccessResetsClosed(): void
    {
        $cb = new CircuitBreaker('test', 3, 10);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        // After success the circuit should reset
        $cb->recordSuccess();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());
    }

    public function testResetClearsAllState(): void
    {
        $cb = new CircuitBreaker('test', 3, 10);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->reset();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        $this->assertSame(0, $cb->getFailureCount());
        $this->assertTrue($cb->isAvailable());
    }

    public function testHalfOpenAfterCooldownExpires(): void
    {
        // Use cooldown of 1 second so we can simulate it
        $cb = new CircuitBreaker('cb_halfopen_test', 1, 1);
        $cb->recordFailure(); // trips with threshold=1
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        // Manually backdate opened_at via the cache so cooldown appears expired
        $cache = service('cache');
        $cache->save('circuit_cb_halfopen_test_opened_at', time() - 2, 60);

        // isAvailable() should transition to HALF_OPEN and return true
        $this->assertTrue($cb->isAvailable());
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());
    }

    public function testHalfOpenProbeSuccessCloses(): void
    {
        $cb = new CircuitBreaker('cb_probe_test', 1, 60);
        $cb->recordFailure();
        // Force half-open state directly in cache
        $cache = service('cache');
        $cache->save('circuit_cb_probe_test_state', CircuitBreaker::STATE_HALF_OPEN, 120);

        // isAvailable should allow through (half_open returns true)
        $this->assertTrue($cb->isAvailable());

        $cb->recordSuccess();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function testHalfOpenProbeFailureReopens(): void
    {
        $cb = new CircuitBreaker('cb_reopen_test', 1, 60);
        $cb->recordFailure();
        // Force half-open state directly in cache
        $cache = service('cache');
        $cache->save('circuit_cb_reopen_test_state', CircuitBreaker::STATE_HALF_OPEN, 120);

        $this->assertTrue($cb->isAvailable()); // probe allowed

        $cb->recordFailure(); // probe fails → re-opens
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
    }
}
