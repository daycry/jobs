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

namespace Tests\Unit\V3\Coverage;

use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Commands\QueueWorkCommand;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Definition\JobDefinition;
use Daycry\Jobs\Libraries\CircuitBreaker;
use Daycry\Jobs\Queues\JobLease;
use Daycry\Jobs\Queues\QueueBackend;
use RuntimeException;

/**
 * Backend whose fetch() always throws, to drive the QueueWorkCommand "error" branch (the circuit
 * breaker records a failure and the cycle returns 'error'). Instantiated arg-less by BackendFactory.
 *
 * @internal
 */
final class CovThrowingBackend implements QueueBackend
{
    public function enqueue(JobDefinition $definition): string
    {
        return 'throw-id';
    }

    public function fetch(string $queue): ?JobLease
    {
        throw new RuntimeException('backend down');
    }

    public function ack(JobLease $lease): bool
    {
        return true;
    }

    public function nack(JobLease $lease, ?int $delaySeconds = null): bool
    {
        return true;
    }

    public function abandon(JobLease $lease): bool
    {
        return true;
    }

    public function reapExpired(string $queue, int $visibilityTimeout): int
    {
        return 0;
    }
}

/**
 * Exercises {@see QueueWorkCommand::processCycle()} branches: rate-limited, circuit-open and the
 * backend-error path. The happy path (sync backend, one cycle) is covered elsewhere; here we focus
 * on the resilience guards. Each test uses a UNIQUE queue so the cache-backed limiter/breaker state
 * never leaks across cases.
 *
 * @internal
 */
final class CovQueueWorkCommandTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        service('cache')->clean();
        parent::tearDown();
    }

    private function command(): QueueWorkCommand
    {
        return new QueueWorkCommand(service('logger'), service('commands'));
    }

    private function jobsConfig(): Jobs
    {
        return config(Jobs::class);
    }

    public function testRunWithSyncBackendProcessesOneEmptyCycle(): void
    {
        $config         = $this->jobsConfig();
        $config->worker = 'sync';

        ob_start();
        $code = $this->command()->run(['queue' => 'covcmdsync', 'max' => '1', 'backend' => 'sync']);
        ob_get_clean();

        $this->assertSame(0, $code);
    }

    public function testRateLimitedCycleReturnsWithoutTouchingBackend(): void
    {
        $config                  = $this->jobsConfig();
        $config->worker          = 'sync';
        $queue                   = 'covcmdrate';
        $config->queueRateLimits = [$queue => 1];

        // Saturate the limiter window so allow() returns false immediately (rate-limited branch).
        service('cache')->save('queue_rate_' . $queue, 5, 60);

        // CLI::write goes straight to STDOUT; capture it only to keep the test output clean.
        ob_start();
        $code = $this->command()->run(['queue' => $queue, 'max' => '1', 'backend' => 'sync']);
        ob_get_clean();

        $this->assertSame(0, $code);
        // The backend was skipped, so the saturated counter is untouched (still 5, not incremented).
        $this->assertSame(5, (int) service('cache')->get('queue_rate_' . $queue));
    }

    public function testCircuitOpenCycleSkipsTheBackend(): void
    {
        $config         = $this->jobsConfig();
        $config->worker = 'sync';
        $queue          = 'covcmdcirc';

        // Open the breaker for this queue: state OPEN with a fresh opened_at keeps it within cooldown.
        $name = 'queue_' . $queue;
        service('cache')->save('circuit_' . $name . '_state', CircuitBreaker::STATE_OPEN, 600);
        service('cache')->save('circuit_' . $name . '_opened_at', time(), 600);

        ob_start();
        $code = $this->command()->run(['queue' => $queue, 'max' => '1', 'backend' => 'sync']);
        ob_get_clean();

        $this->assertSame(0, $code);
        // The cycle short-circuited on the open breaker (no recordSuccess), so it stays OPEN.
        $breaker = new CircuitBreaker($name, $config->circuitBreakerThreshold, $config->circuitBreakerCooldown);
        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());
    }

    public function testBackendErrorCycleIsReportedAndRecordedAsFailure(): void
    {
        $config                       = $this->jobsConfig();
        $config->backends['covthrow'] = CovThrowingBackend::class;
        $config->worker               = 'covthrow';
        $queue                        = 'covcmderr';

        ob_start();
        $code = $this->command()->run(['queue' => $queue, 'max' => '1', 'backend' => 'covthrow']);
        ob_get_clean();

        $this->assertSame(0, $code);
        // The throwing fetch() drove the error branch, which records a breaker failure.
        $failures = service('cache')->get('circuit_queue_' . $queue . '_failures');
        $this->assertSame(1, (int) $failures);
    }
}
