<?php

declare(strict_types=1);

namespace Tests\Unit\V3\Execution;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use Daycry\Jobs\Execution\IdempotencyGuard;

/**
 * @internal
 */
final class IdempotencyGuardTest extends CIUnitTestCase
{
    private function makeGuard(): IdempotencyGuard
    {
        $cache = new MockCache();
        $cache->initialize();

        return new IdempotencyGuard($cache);
    }

    public function testFirstRunReturnsTrueOnFirstSeen(): void
    {
        $guard = $this->makeGuard();

        $this->assertTrue($guard->firstRun('k'));
    }

    public function testFirstRunReturnsFalseOnSecondSeen(): void
    {
        $guard = $this->makeGuard();

        $this->assertTrue($guard->firstRun('k'));
        $this->assertFalse($guard->firstRun('k'));
    }

    public function testForgetAllowsKeyToRunAgain(): void
    {
        $guard = $this->makeGuard();

        $this->assertTrue($guard->firstRun('k'));
        $this->assertFalse($guard->firstRun('k'));

        $guard->forget('k');

        $this->assertTrue($guard->firstRun('k'));
    }

    public function testCustomTtlIsAccepted(): void
    {
        $guard = $this->makeGuard();

        $this->assertTrue($guard->firstRun('k', 5));
        $this->assertFalse($guard->firstRun('k', 5));
    }
}
