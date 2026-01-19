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

namespace Tests\Unit\Libraries;

use Daycry\Jobs\Config\Jobs as JobsConfig;
use Daycry\Jobs\Libraries\ConfigCache;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ConfigCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigCache::clear();
        parent::tearDown();
    }

    public function testGetReturnsSameInstanceOnMultipleCalls(): void
    {
        $first  = ConfigCache::get();
        $second = ConfigCache::get();

        $this->assertInstanceOf(JobsConfig::class, $first);
        $this->assertSame($first, $second, 'Should return same cached instance');
    }

    public function testClearResetsCache(): void
    {
        $first = ConfigCache::get();
        ConfigCache::clear();

        // After clear, the instance should be null and will be recreated on next get
        $this->assertInstanceOf(JobsConfig::class, $first);

        // Verify clear worked by setting a custom instance
        $customConfig             = new JobsConfig();
        $customConfig->jobTimeout = 123;
        ConfigCache::set($customConfig);

        $retrieved = ConfigCache::get();
        $this->assertSame($customConfig, $retrieved);
        $this->assertSame(123, $retrieved->jobTimeout);
    }

    public function testSetAllowsCustomConfig(): void
    {
        $customConfig             = new JobsConfig();
        $customConfig->jobTimeout = 999;

        ConfigCache::set($customConfig);
        $retrieved = ConfigCache::get();

        $this->assertSame($customConfig, $retrieved);
        $this->assertSame(999, $retrieved->jobTimeout);
    }

    public function testSetThenClearReturnsToDefaultConfig(): void
    {
        $customConfig             = new JobsConfig();
        $customConfig->jobTimeout = 999;

        ConfigCache::set($customConfig);
        $this->assertSame(999, ConfigCache::get()->jobTimeout);

        ConfigCache::clear();
        $this->assertNotSame(999, ConfigCache::get()->jobTimeout);
    }

    public function testMultipleGetCallsWithoutClearUseSameInstance(): void
    {
        $instances = [];

        for ($i = 0; $i < 10; $i++) {
            $instances[] = ConfigCache::get();
        }

        $first = $instances[0];

        foreach ($instances as $instance) {
            $this->assertSame($first, $instance);
        }
    }
}
