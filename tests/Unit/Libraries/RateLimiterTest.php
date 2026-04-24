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

use Config\Services;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Libraries\RateLimiter;
use Tests\Support\TestCase;

final class CacheWithoutIncrement
{
    /**
     * @var array<string, int>
     */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function save(string $key, mixed $value): bool
    {
        $this->store[$key] = (int) $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }
}

/**
 * @internal
 */
final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('cache');
        // Clean rate counters
        service('cache')->delete('queue_rate_rlqueue');
        service('cache')->delete('queue_rate_rlthrottle');
    }

    protected function tearDown(): void
    {
        service('cache')->delete('queue_rate_rlqueue');
        service('cache')->delete('queue_rate_rlthrottle');
        Services::resetSingle('cache');
        parent::tearDown();
    }

    public function testAllowReturnsTrueForUnlimited(): void
    {
        $rl = new RateLimiter();
        $this->assertTrue($rl->allow('rlqueue', 0));
    }

    public function testAllowReturnsTrueOnFirstRequest(): void
    {
        $rl = new RateLimiter();
        $this->assertTrue($rl->allow('rlqueue', 10));
    }

    public function testAllowReturnsFalseWhenLimitExceeded(): void
    {
        $rl = new RateLimiter();

        // Exhaust the limit manually
        for ($i = 0; $i < 3; $i++) {
            $rl->allow('rlqueue', 3);
        }
        $this->assertFalse($rl->allow('rlqueue', 3));
    }

    public function testGetUsageReturnsCount(): void
    {
        $rl = new RateLimiter();
        $rl->allow('rlqueue', 5);
        $rl->allow('rlqueue', 5);
        $this->assertGreaterThanOrEqual(1, $rl->getUsage('rlqueue'));
    }

    public function testGetUsageReturnsZeroWhenNoneUsed(): void
    {
        $rl = new RateLimiter();
        $this->assertSame(0, $rl->getUsage('rlqueue'));
    }

    public function testResetClearsCount(): void
    {
        $rl = new RateLimiter();
        $rl->allow('rlqueue', 5);
        $rl->allow('rlqueue', 5);
        $rl->reset('rlqueue');
        $this->assertSame(0, $rl->getUsage('rlqueue'));
    }

    public function testThrottleDoesNotThrowWhenUnderLimit(): void
    {
        $rl = new RateLimiter();
        $rl->throttle('rlthrottle', 10); // Should not throw
        $this->assertGreaterThanOrEqual(1, $rl->getUsage('rlthrottle'));
    }

    public function testThrottleThrowsWhenLimitExceeded(): void
    {
        $this->expectException(JobException::class);
        $rl = new RateLimiter();

        // Exhaust the limit
        for ($i = 0; $i < 2; $i++) {
            $rl->allow('rlthrottle', 2);
        }
        $rl->throttle('rlthrottle', 2); // Should throw
    }

    public function testAllowThrottlesViaAtomicIncrementPath(): void
    {
        $rl = new RateLimiter();
        service('cache')->delete('queue_rate_rlatomic');

        // maxPerMinute=1: first call -> allow; second -> throttle
        $this->assertTrue($rl->allow('rlatomic', 1));
        $this->assertFalse($rl->allow('rlatomic', 1));

        service('cache')->delete('queue_rate_rlatomic');
    }

    public function testAllowIncrementsBeyondFirst(): void
    {
        $rl = new RateLimiter();
        service('cache')->delete('queue_rate_rlinc');

        // maxPerMinute=3: first call initializes, second call increments
        $this->assertTrue($rl->allow('rlinc', 3));
        $this->assertTrue($rl->allow('rlinc', 3)); // increment path
        $this->assertTrue($rl->allow('rlinc', 3));
        $this->assertFalse($rl->allow('rlinc', 3)); // should be throttled now

        service('cache')->delete('queue_rate_rlinc');
    }

    public function testAllowFallbackPathWithoutIncrementMethod(): void
    {
        Services::injectMock('cache', new CacheWithoutIncrement());
        $rl = new RateLimiter();

        $this->assertTrue($rl->allow('fallback', 2));
        $this->assertTrue($rl->allow('fallback', 2));
        $this->assertFalse($rl->allow('fallback', 2));
    }

    public function testAllowFallbackPathStoresIncrementedCounter(): void
    {
        Services::injectMock('cache', new CacheWithoutIncrement());
        $rl = new RateLimiter();

        $this->assertTrue($rl->allow('fallback_usage', 3));
        $this->assertSame(1, $rl->getUsage('fallback_usage'));
    }
}
