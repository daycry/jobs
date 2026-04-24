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

namespace Tests\Unit\Metrics;

use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Metrics\Metrics;
use Daycry\Jobs\Metrics\MetricsCollectorInterface;
use stdClass;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class MetricsExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Metrics::reset();
    }

    protected function tearDown(): void
    {
        Metrics::reset();
        parent::tearDown();
    }

    public function testGetReturnsInMemoryByDefault(): void
    {
        $this->jobsConfig()->metricsCollector = InMemoryMetricsCollector::class;
        $collector                            = Metrics::get();
        $this->assertInstanceOf(InMemoryMetricsCollector::class, $collector);
    }

    public function testGetReturnsSameInstanceOnRepeatCalls(): void
    {
        $this->jobsConfig()->metricsCollector = InMemoryMetricsCollector::class;
        $a                                    = Metrics::get();
        $b                                    = Metrics::get();
        $this->assertSame($a, $b);
    }

    public function testGetReturnsNullWhenDisabled(): void
    {
        $this->jobsConfig()->metricsCollector = null;
        Metrics::reset();
        $collector = Metrics::get();
        $this->assertNotInstanceOf(MetricsCollectorInterface::class, $collector);
    }

    public function testGetFallsBackToInMemoryForBadClass(): void
    {
        $this->jobsConfig()->metricsCollector = 'NonExistentClass\\Foo';
        Metrics::reset();
        $collector = Metrics::get();
        $this->assertInstanceOf(InMemoryMetricsCollector::class, $collector);
    }

    public function testGetFallsBackToInMemoryForClassNotImplementingInterface(): void
    {
        // A class that exists but does NOT implement MetricsCollectorInterface
        $this->jobsConfig()->metricsCollector = stdClass::class;
        Metrics::reset();
        $collector = Metrics::get();
        // Should fall back to InMemoryMetricsCollector
        $this->assertInstanceOf(InMemoryMetricsCollector::class, $collector);
    }

    public function testResetAllowsNewInstanceCreation(): void
    {
        $this->jobsConfig()->metricsCollector = InMemoryMetricsCollector::class;
        $a                                    = Metrics::get();
        Metrics::reset();
        $b = Metrics::get();
        $this->assertNotSame($a, $b);
    }
}
