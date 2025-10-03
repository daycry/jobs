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

final class InMemoryMetricsCollector implements MetricsCollectorInterface
{
    private array $counters   = [];
    private array $histograms = [];

    public function increment(string $counter, int $value = 1, array $labels = []): void
    {
        $key                  = $this->key($counter, $labels);
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $value;
    }

    public function observe(string $metric, float $value, array $labels = []): void
    {
        $key    = $this->key($metric, $labels);
        $bucket = $this->histograms[$key] ?? ['count' => 0, 'sum' => 0.0, 'min' => null, 'max' => null];
        $bucket['count']++;
        $bucket['sum'] += $value;
        $bucket['min']          = $bucket['min'] === null ? $value : min($bucket['min'], $value);
        $bucket['max']          = $bucket['max'] === null ? $value : max($bucket['max'], $value);
        $this->histograms[$key] = $bucket;
    }

    public function getSnapshot(): array
    {
        return [
            'counters'   => $this->counters,
            'histograms' => $this->histograms,
        ];
    }

    private function key(string $name, array $labels): string
    {
        if ($labels === []) {
            return $name;
        }
        ksort($labels);

        return $name . '|' . http_build_query($labels, '', ';');
    }
}
