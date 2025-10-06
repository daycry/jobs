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

use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MetricsCollectorTest extends TestCase
{
    public function testIncrementAndObserve(): void
    {
        $mc = new InMemoryMetricsCollector();
        $mc->increment('jobs_fetched');
        $mc->increment('jobs_fetched', 2, ['queue' => 'alpha']);
        $mc->increment('jobs_fetched', 3, ['queue' => 'alpha']); // cumulative with labels
        $mc->observe('jobs_exec_seconds', 0.5, ['queue' => 'alpha']);
        $mc->observe('jobs_exec_seconds', 1.5, ['queue' => 'alpha']);
        $mc->observe('jobs_exec_seconds', 1.0, ['queue' => 'beta']);

        $snapshot = $mc->getSnapshot();

        $this->assertSame(1, $snapshot['counters']['jobs_fetched']);
        $this->assertSame(5, $snapshot['counters']['jobs_fetched|queue=alpha']);
        $histAlpha = $snapshot['histograms']['jobs_exec_seconds|queue=alpha'];
        $this->assertSame(2, $histAlpha['count']);
        $this->assertSame(2.0, $histAlpha['sum']);
        $this->assertSame(0.5, $histAlpha['min']);
        $this->assertSame(1.5, $histAlpha['max']);
        $this->assertSame(1, $snapshot['histograms']['jobs_exec_seconds|queue=beta']['count']);
    }
}
