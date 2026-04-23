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

        // Attempt atomic increment if the cache driver supports it (e.g. Redis, Memcached).
        // This prevents race conditions between get() and save() calls.
        if (method_exists($cache, 'increment')) {
            $current = $cache->get($key);

            if ($current === null) {
                // First request in this window: initialize counter
                $cache->save($key, 1, 60);

                return true;
            }

            if ((int) $current >= $maxPerMinute) {
                return false; // Throttled
            }

            $cache->increment($key, 1);

            return true;
        }

        // Fallback for cache drivers without atomic increment
        $count = (int) ($cache->get($key) ?? 0);

        if ($count >= $maxPerMinute) {
            return false; // Throttled
        }

        // Increment counter (expires after 60 seconds)
        $cache->save($key, $count + 1, 60);

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
