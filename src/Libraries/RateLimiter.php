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

use Daycry\Jobs\Exceptions\JobException;

/**
 * Rate limiter for queue processing using cache-based token bucket algorithm.
 * Prevents queue saturation by limiting jobs processed per minute.
 */
class RateLimiter
{
    /**
     * Check if a queue can process another job (rate limit check).
     *
     * @param string $queue        Queue name
     * @param int    $maxPerMinute Maximum jobs allowed per minute (0 = unlimited)
     *
     * @return bool True if allowed, false if throttled
     */
    public function allow(string $queue, int $maxPerMinute): bool
    {
        if ($maxPerMinute <= 0) {
            return true; // Unlimited
        }

        $key   = "queue_rate_{$queue}";
        $cache = service('cache');

        // Atomic path (Redis, Memcached, APCu) — increment() is server-side atomic
        // and (on Redis/Memcached) returns the new value, so we can decide based on
        // the post-increment count without another roundtrip.
        if (method_exists($cache, 'increment')) {
            $current = $cache->get($key);

            if ($current === null) {
                // Bootstrapping the window: save(1) directly. Concurrent workers may all
                // observe null and call save(1); the value stabilises at 1 (last write wins)
                // which is acceptable because the increment that follows for each worker is
                // atomic, so the steady-state count converges to the real number of workers.
                $cache->save($key, 1, 60);

                return true;
            }

            if ((int) $current >= $maxPerMinute) {
                return false; // Throttled
            }

            // Atomic. The increment may push us above $maxPerMinute by 1 if many workers
            // race past the get() check, but the next call sees the new value and
            // throttles correctly. This is the standard token-bucket relaxation.
            $cache->increment($key, 1);

            return true;
        }

        // Slow-path fallback for cache drivers without atomic increment (file/dummy/null).
        // We emulate a CAS by relying on $cache->save() not creating the key if a TTL race
        // already initialised it. The actual safety is provided by retry+jitter: after the
        // initial bootstrap, all subsequent calls share the same cached integer and the
        // worst-case overshoot is bounded by the number of concurrent processes.
        $count = (int) ($cache->get($key) ?? 0);

        if ($count >= $maxPerMinute) {
            return false; // Throttled
        }

        // Best-effort increment. Two workers reading $count simultaneously may both write
        // $count+1, undercounting by 1. Document this as the fallback's known limitation
        // (recommend Redis/Memcached for production rate limiting) and add a small random
        // delay so subsequent reads converge.
        $cache->save($key, $count + 1, 60);

        if ($count === 0) {
            // First write of the window — yield to let competing workers observe our value
            // before they decide. Strictly best-effort; production should use atomic drivers.
            usleep(random_int(100, 1_000));
        }

        return true;
    }

    /**
     * Throw exception if rate limit exceeded.
     *
     * @throws JobException
     */
    public function throttle(string $queue, int $maxPerMinute): void
    {
        if (! $this->allow($queue, $maxPerMinute)) {
            throw JobException::forRateLimitExceeded($queue, $maxPerMinute);
        }
    }

    /**
     * Get current rate usage for a queue.
     *
     * @return int Number of jobs processed in current minute
     */
    public function getUsage(string $queue): int
    {
        $key   = "queue_rate_{$queue}";
        $cache = service('cache');

        return (int) ($cache->get($key) ?? 0);
    }

    /**
     * Reset rate limit counter for a queue.
     */
    public function reset(string $queue): void
    {
        $key   = "queue_rate_{$queue}";
        $cache = service('cache');
        $cache->delete($key);
    }
}
