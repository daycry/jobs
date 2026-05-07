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
    /**
     * Hard cap on the number of distinct (metric|labels) keys retained in memory.
     * Once reached, the oldest entries are evicted in FIFO order so long-running workers
     * cannot grow unbounded. Configure to 0 to disable the cap (legacy behaviour).
     */
    private int $maxCardinality = 5_000;

    private array $counters   = [];
    private array $histograms = [];

    public function __construct(?int $maxCardinality = null)
    {
        if ($maxCardinality !== null) {
            $this->maxCardinality = max(0, $maxCardinality);
        }
    }

    public function increment(string $counter, int $value = 1, array $labels = []): void
    {
        $key = $this->key($counter, $labels);
        $this->evictIfNeeded($this->counters, $key);
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $value;
    }

    public function observe(string $metric, float $value, array $labels = []): void
    {
        $key = $this->key($metric, $labels);
        $this->evictIfNeeded($this->histograms, $key);
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

    /**
     * Reset all collected metrics to free memory in long-running workers.
     * Worker loops should call this every N iterations (e.g. via Metrics::get()->reset()).
     */
    public function reset(): void
    {
        $this->counters   = [];
        $this->histograms = [];
    }

    /**
     * Evict the oldest entry from $store when the cap is reached and the new key is not
     * already present. PHP arrays preserve insertion order so reset()ing the front works
     * via array_shift / unset of the first key.
     *
     * @param array<string, mixed> $store
     */
    private function evictIfNeeded(array &$store, string $newKey): void
    {
        if ($this->maxCardinality <= 0) {
            return;
        }
        if (isset($store[$newKey])) {
            return;
        }
        if (count($store) < $this->maxCardinality) {
            return;
        }

        $first = array_key_first($store);
        // array_key_first() on a non-empty array returns a key (we already early-returned
        // when count() === 0), but the static analysers are conservative about its signature.
        unset($store[$first]);
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
