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

use Throwable;

/**
 * Unified retry policy with configurable strategies: none, fixed, exponential.
 */
class RetryPolicyFixed implements RetryPolicy
{
    public function __construct(
        private int $base = 5,
        private string $strategy = 'fixed',
        private float $multiplier = 2.0,
        private int $max = 300,
        private bool $jitter = true,
    ) {
        $this->base       = max(0, $base);
        $this->multiplier = $multiplier > 0 ? $multiplier : 2.0;
        $this->max        = max(1, $max);
    }

    public function computeDelay(int $attempt): int
    {
        if ($attempt <= 1) {
            return 0;
        }

        return match ($this->strategy) {
            'exponential' => $this->computeExponential($attempt),
            'fixed'       => $this->base,
            default       => 0, // 'none'
        };
    }

    private function computeExponential(int $attempt): int
    {
        // attempt=2 => exponent 0
        $exponent = $attempt - 2;
        $delay    = (int) round($this->base * ($this->multiplier ** $exponent));
        $delay    = min($delay, $this->max);

        if ($this->jitter) {
            $range = max(1, (int) round($delay * 0.15));

            try {
                $delay = max(1, $delay + random_int(-$range, $range));
            } catch (Throwable) {
                // Fallback sin jitter
            }
        }

        return $delay;
    }
}
