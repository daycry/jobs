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
 * Prevents concurrent executions of the same named job by holding an ownership
 * token in the cache (fix #19): only the owner that acquired the lock may release it.
 *
 * The implementation is best-effort and NOT strictly atomic unless the underlying
 * cache driver provides a native SET NX semantics. Between {@see self::acquire()}'s
 * read and write a competing process could squeeze in; the ownership token still
 * guarantees that a release never frees a lock held by a different owner, which is
 * the property that matters for cleanup correctness.
 */
final readonly class SingleInstanceLock
{
    /**
     * Cache key prefix shared by every lock entry.
     */
    private const KEY_PREFIX = 'jobs_lock_';

    public function __construct(private ?CacheInterface $cache = null)
    {
    }

    /**
     * Attempts to take the lock for the given job $name on behalf of $owner.
     *
     * @param string $name  Logical job name to lock.
     * @param string $owner Opaque ownership token identifying the caller.
     * @param int    $ttl   Lock lifetime in seconds.
     *
     * @return bool true when the lock is acquired (and the owner recorded);
     *              false when it is already held by a DIFFERENT owner.
     */
    public function acquire(string $name, string $owner, int $ttl): bool
    {
        $cache   = $this->resolveCache();
        $key     = self::KEY_PREFIX . $name;
        $current = $cache->get($key);

        if ($current !== null && $current !== $owner) {
            return false;
        }

        $cache->save($key, $owner, $ttl);

        return true;
    }

    /**
     * Releases the lock for $name only when $owner is the current holder.
     *
     * @param string $name  Logical job name to release.
     * @param string $owner Ownership token that must match the recorded owner.
     *
     * @return bool true when the lock was owned by $owner and removed; false otherwise.
     */
    public function release(string $name, string $owner): bool
    {
        $cache = $this->resolveCache();
        $key   = self::KEY_PREFIX . $name;

        if ($cache->get($key) === $owner) {
            $cache->delete($key);

            return true;
        }

        return false;
    }

    /**
     * Resolves the injected cache or falls back to the shared 'cache' service.
     */
    private function resolveCache(): CacheInterface
    {
        return $this->cache ?? service('cache');
    }
}
