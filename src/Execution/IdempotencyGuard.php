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

namespace Daycry\Jobs\Execution;

use CodeIgniter\Cache\CacheInterface;

/**
 * Opt-in deduplication guard (fix #5).
 *
 * Marks a key as "already seen" so the same logical job is not re-executed. This is a
 * best-effort guarantee: the check-then-set is NOT atomic, so two concurrent workers
 * racing on the same key could both observe a miss. Backends offering an atomic
 * SET key value NX EX ttl (e.g. Redis) close that window; the cache-agnostic
 * implementation here does not, and callers must tolerate rare double execution.
 */
final readonly class IdempotencyGuard
{
    /**
     * Prefix applied to every idempotency key stored in the cache.
     */
    private const KEY_PREFIX = 'jobs_idem_';

    public function __construct(private ?CacheInterface $cache = null)
    {
    }

    /**
     * Returns true when the key has NOT been seen before (registering it in the process);
     * returns false when the key was already registered.
     *
     * Best-effort: not atomic unless the underlying cache backend supports SET NX.
     *
     * @param int|null $ttl Time-to-live in seconds; defaults to config('Jobs')->idempotencyTtl.
     */
    public function firstRun(string $key, ?int $ttl = null): bool
    {
        $cache       = $this->resolveCache();
        $prefixedKey = self::KEY_PREFIX . $key;

        if ($cache->get($prefixedKey) !== null) {
            return false;
        }

        $cache->save($prefixedKey, 1, $ttl ?? config('Jobs')->idempotencyTtl);

        return true;
    }

    /**
     * Removes the idempotency mark for a key so a controlled retry may run again.
     */
    public function forget(string $key): void
    {
        $this->resolveCache()->delete(self::KEY_PREFIX . $key);
    }

    private function resolveCache(): CacheInterface
    {
        return $this->cache ?? service('cache');
    }
}
