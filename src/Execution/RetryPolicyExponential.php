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

class RetryPolicyExponential implements RetryPolicy
{
    public function __construct(
        private int $base,
        private float $multiplier,
        private int $max,
        private bool $jitter,
    ) {
        $this->base       = max(1, $base);
        $this->multiplier = $multiplier > 0 ? $multiplier : 2.0;
        $this->max        = max(1, $max);
    }

    public function computeDelay(int $attempt): int
    {
        if ($attempt <= 1) {
            return 0;
        }

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
