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

use Daycry\Jobs\Exceptions\JobException;

/**
 * Runs a callable under a wall-clock timeout.
 *
 * When ext-pcntl is available the SIGALRM handler THROWS, so even CPU-bound or
 * blocking code is interrupted at the deadline (this is the v3 fix for the legacy
 * coordinator whose handler only set a flag and therefore never interrupted anything).
 * Without pcntl (e.g. Windows) it degrades to a soft, post-hoc check that cannot abort a
 * runaway job — documented as a limitation.
 *
 * A timeout surfaces as {@see JobException::forJobTimeout()}.
 */
final class Timeout
{
    /**
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function run(int $seconds, callable $callback, string $label = 'job'): mixed
    {
        if ($seconds <= 0) {
            return $callback();
        }

        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
            return $this->runWithAlarm($seconds, $callback, $label);
        }

        // Soft fallback: cannot hard-interrupt; measure and fail if the deadline was exceeded.
        $start  = microtime(true);
        $result = $callback();
        if (microtime(true) - $start > $seconds) {
            throw JobException::forJobTimeout($label, $seconds);
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    private function runWithAlarm(int $seconds, callable $callback, string $label): mixed
    {
        $previousAsync = false;
        if (function_exists('pcntl_async_signals')) {
            $previousAsync = pcntl_async_signals(true);
        }

        $previousHandler = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGALRM)
            : SIG_DFL;

        // The handler THROWS so CPU-bound / blocking code is interrupted at the deadline.
        pcntl_signal(SIGALRM, static function () use ($label, $seconds): void {
            throw JobException::forJobTimeout($label, $seconds);
        });
        pcntl_alarm($seconds);

        try {
            return $callback();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals($previousAsync);
            }
        }
    }
}
