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

class RetryPolicyFixed implements RetryPolicy
{
    public function __construct(private int $base)
    {
        $this->base = max(0, $base);
    }

    public function computeDelay(int $attempt): int
    {
        if ($attempt <= 1) {
            return 0;
        }

        return $this->base;
    }
}
