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

class RetryPolicyFactory
{
    public function for(ExecutionContext $ctx): RetryPolicy
    {
        $cfg        = $ctx->retryConfig;
        $strategy   = $cfg['strategy'] ?? 'none';
        $base       = (int) ($cfg['base'] ?? 5);
        $multiplier = (float) ($cfg['multiplier'] ?? 2.0);
        $max        = (int) ($cfg['max'] ?? 300);
        $jitter     = (bool) ($cfg['jitter'] ?? true);

        return match ($strategy) {
            'fixed'       => new RetryPolicyFixed($base),
            'exponential' => new RetryPolicyExponential($base, $multiplier, $max, $jitter),
            default       => new RetryPolicyNone(),
        };
    }
}
