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

namespace Daycry\Jobs\Metrics;

use Throwable;

/**
 * Lightweight facade to retrieve a singleton MetricsCollectorInterface implementation
 * configured via Jobs::$metricsCollector. Falls back to InMemory unless explicitly disabled (null).
 */
final class Metrics
{
    private static bool $booted                         = false;
    private static ?MetricsCollectorInterface $instance = null;

    public static function get(): ?MetricsCollectorInterface
    {
        if (self::$booted) {
            return self::$instance; // could be null if disabled
        }
        self::$booted = true;
        $config       = config('Jobs');
        $class        = $config->metricsCollector ?? null;
        if ($class === null) {
            // Metrics disabled
            return self::$instance = null;
        }
        if (class_exists($class)) {
            try {
                $obj = new $class();
                if ($obj instanceof MetricsCollectorInterface) {
                    return self::$instance = $obj;
                }
            } catch (Throwable) {
                // ignore and fallback
            }
        }

        // Fallback to in-memory collector for resilience
        return self::$instance = new InMemoryMetricsCollector();
    }

    /**
     * Resets the cached instance (primarily for tests).
     */
    public static function reset(): void
    {
        self::$booted   = false;
        self::$instance = null;
    }
}
