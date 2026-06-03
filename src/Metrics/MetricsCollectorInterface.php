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
    /**
     * @param array<string, scalar> $labels
     */
    public function increment(string $counter, int $value = 1, array $labels = []): void;

    /**
     * @param array<string, scalar> $labels
     */
    public function observe(string $metric, float $value, array $labels = []): void;

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array;
}
