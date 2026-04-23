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

namespace Daycry\Jobs\Libraries;

/**
 * Circuit Breaker pattern for queue backend resilience.
 *
 * States:
 *  - CLOSED: Normal operation. Failures are counted.
 *  - OPEN: Backend is considered unavailable. All calls are skipped for a cooldown period.
 *  - HALF_OPEN: After cooldown expires, one probe call is allowed to test recovery.
 *
 * State is stored in the cache service so it persists across worker restarts.
 */
class CircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 60,
    ) {
    }

    /**
     * Check if the backend is available for use.
     * Returns false when the circuit is OPEN (within cooldown period).
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $openedAt = (int) $this->cacheGet('opened_at');
            if (time() - $openedAt >= $this->cooldownSeconds) {
                // Cooldown expired, transition to half-open
                $this->cacheSet('state', self::STATE_HALF_OPEN, $this->cooldownSeconds * 2);

                return true; // Allow one probe
            }

            return false; // Still in cooldown
        }

        // HALF_OPEN: allow probe
        return true;
    }

    /**
     * Record a successful operation. Resets the circuit to CLOSED.
     */
    public function recordSuccess(): void
    {
        $this->cacheSet('failures', 0, $this->cooldownSeconds * 2);
        $this->cacheSet('state', self::STATE_CLOSED, $this->cooldownSeconds * 2);
    }

    /**
     * Record a failed operation. Increments failure counter and may trip the circuit.
     */
    public function recordFailure(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            // Probe failed, re-open the circuit
            $this->tripCircuit();

            return;
        }

        $failures = (int) $this->cacheGet('failures') + 1;
        $this->cacheSet('failures', $failures, $this->cooldownSeconds * 2);

        if ($failures >= $this->failureThreshold) {
            $this->tripCircuit();
        }
    }

    public function getState(): string
    {
        return (string) ($this->cacheGet('state') ?: self::STATE_CLOSED);
    }

    public function getFailureCount(): int
    {
        return (int) ($this->cacheGet('failures') ?: 0);
    }

    /**
     * Manually reset the circuit breaker to CLOSED state.
     */
    public function reset(): void
    {
        $cache = service('cache');
        $cache->delete($this->key('failures'));
        $cache->delete($this->key('state'));
        $cache->delete($this->key('opened_at'));
    }

    private function tripCircuit(): void
    {
        $this->cacheSet('state', self::STATE_OPEN, $this->cooldownSeconds * 2);
        $this->cacheSet('opened_at', time(), $this->cooldownSeconds * 2);
        $this->cacheSet('failures', 0, $this->cooldownSeconds * 2);
    }

    private function key(string $suffix): string
    {
        return "circuit_{$this->name}_{$suffix}";
    }

    private function cacheGet(string $suffix): mixed
    {
        return service('cache')->get($this->key($suffix));
    }

    private function cacheSet(string $suffix, mixed $value, int $ttl): void
    {
        service('cache')->save($this->key($suffix), $value, $ttl);
    }
}
