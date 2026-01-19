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

use Daycry\Jobs\Config\Jobs;

/**
 * Singleton config cache to avoid repeated config() calls.
 * Improves performance by caching the Jobs config in memory.
 */
class ConfigCache
{
    private static ?Jobs $instance = null;

    /**
     * Get cached config instance.
     */
    public static function get(): Jobs
    {
        return self::$instance ??= config('Jobs');
    }

    /**
     * Clear cached instance (useful for testing).
     */
    public static function clear(): void
    {
        self::$instance = null;
    }

    /**
     * Set custom config instance (useful for testing).
     */
    public static function set(Jobs $config): void
    {
        self::$instance = $config;
    }
}
