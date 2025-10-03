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

interface MetricsCollectorInterface
{
    public function increment(string $counter, int $value = 1, array $labels = []): void;

    public function observe(string $metric, float $value, array $labels = []): void;

    public function getSnapshot(): array;
}
