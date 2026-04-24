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

use Daycry\Jobs\Execution\RetryPolicyFixed;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RetryPoliciesExtraTest extends TestCase
{
    public function testFixedPolicyBaseDelay(): void
    {
        $p = new RetryPolicyFixed(base: 5, strategy: 'fixed');
        $this->assertSame(0, $p->computeDelay(1));
        $this->assertSame(5, $p->computeDelay(2));
        $this->assertSame(5, $p->computeDelay(5));
    }

    public function testExponentialPolicyNoJitter(): void
    {
        $p = new RetryPolicyFixed(base: 2, strategy: 'exponential', multiplier: 3.0, max: 50, jitter: false);
        // attempt=1 => 0
        $this->assertSame(0, $p->computeDelay(1));
        // attempt=2 exponent 0 => 2
        $this->assertSame(2, $p->computeDelay(2));
        // attempt=3 exponent 1 => 2*3 = 6
        $this->assertSame(6, $p->computeDelay(3));
        // attempt=6 exponent 4 => 2*(3^4)=162 -> capped at 50
        $this->assertSame(50, $p->computeDelay(6));
    }

    public function testExponentialPolicyJitter(): void
    {
        $p   = new RetryPolicyFixed(base: 2, strategy: 'exponential', multiplier: 2.0, max: 40, jitter: true);
        $val = $p->computeDelay(5); // attempt=5 exponent=3 => base*(2^3)=16 (within max)
        $this->assertGreaterThanOrEqual(1, $val);
        $this->assertLessThanOrEqual(40, $val);
    }

    public function testExponentialPolicyOverflowGuard(): void
    {
        // exponent > 30 triggers overflow guard → clamp to max
        $p   = new RetryPolicyFixed(base: 1, strategy: 'exponential', multiplier: 2.0, max: 100, jitter: false);
        $val = $p->computeDelay(35); // exponent = 33 > 30 → overflow guard
        $this->assertSame(100, $val);
    }

    public function testNoneStrategyReturnsZero(): void
    {
        $p = new RetryPolicyFixed(base: 5, strategy: 'none');
        $this->assertSame(0, $p->computeDelay(1));
        $this->assertSame(0, $p->computeDelay(5));
    }

    public function testNegativeBaseClampedToZero(): void
    {
        $p = new RetryPolicyFixed(base: -10, strategy: 'fixed');
        $this->assertSame(0, $p->computeDelay(2));
    }

    public function testBadMultiplierDefaultsToTwo(): void
    {
        $p   = new RetryPolicyFixed(base: 2, strategy: 'exponential', multiplier: 0, max: 100, jitter: false);
        $val = $p->computeDelay(3); // exponent 1 => 2*(2^1) = 4
        $this->assertSame(4, $val);
    }
}
