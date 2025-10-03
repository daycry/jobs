<?php

declare(strict_types=1);

use Daycry\Jobs\Execution\RetryPolicyFixed;
use Daycry\Jobs\Execution\RetryPolicyExponential;
use PHPUnit\Framework\TestCase;

final class RetryPoliciesExtraTest extends TestCase
{
    public function testFixedPolicyBaseDelay(): void
    {
        $p = new RetryPolicyFixed(5);
        $this->assertSame(0, $p->computeDelay(1));
        $this->assertSame(5, $p->computeDelay(2));
        $this->assertSame(5, $p->computeDelay(5));
    }

    public function testExponentialPolicyNoJitter(): void
    {
        $p = new RetryPolicyExponential(base: 2, multiplier: 3.0, max: 50, jitter: false);
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
        $p = new RetryPolicyExponential(base: 2, multiplier: 2.0, max: 40, jitter: true);
        $val = $p->computeDelay(5); // attempt=5 exponent=3 => base*(2^3)=16 (within max)
        $this->assertGreaterThanOrEqual(1, $val);
        $this->assertLessThanOrEqual(40, $val);
    }
}
