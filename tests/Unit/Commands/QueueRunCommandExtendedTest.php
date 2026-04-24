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

use Daycry\Jobs\Commands\QueueRunCommand;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\CircuitBreaker;
use Daycry\Jobs\Libraries\RateLimiter;
use Daycry\Jobs\Queues\JobEnvelope;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class QueueRunCommandExtendedTest extends TestCase
{
    /**
     * Build a stub QueueRunCommand that uses a given worker.
     */
    private function buildCmd(mixed $worker): QueueRunCommand
    {
        return new class ($worker) extends QueueRunCommand {
            /**
             * @phpstan-ignore constructor.unusedParameter
             */
            public function __construct(private readonly mixed $workerInstance)
            {
            }

            protected function getWorker(): mixed
            {
                return $this->workerInstance;
            }
        };
    }

    private function makeEnvelope(?string $job = 'command', ?string $payload = 'jobs:test'): JobEnvelope
    {
        return new JobEnvelope(
            id: 'test-id-1',
            queue: 'default',
            payload: (object) ['job' => $job, 'payload' => $payload, 'name' => 'test_job'],
            attempts: 0,
        );
    }

    private function makeEnvelopeWithCreatedAt(?string $job = 'command', ?string $payload = 'jobs:test'): JobEnvelope
    {
        return new JobEnvelope(
            id: 'test-id-2',
            queue: 'default',
            payload: (object) ['job' => $job, 'payload' => $payload, 'name' => 'test_job'],
            attempts: 0,
            createdAt: new DateTimeImmutable('-30 seconds'),
        );
    }

    private function makeStubWorker(?JobEnvelope $envelope): object
    {
        return new class ($envelope) {
            public bool $removeJobCalled = false;

            public function __construct(private ?JobEnvelope $env)
            {
            }

            public function watch(string $queue): mixed
            {
                $e         = $this->env;
                $this->env = null;

                return $e;
            }

            public function removeJob(Job $job, bool $recreate): bool
            {
                $this->removeJobCalled = true;

                return true;
            }
        };
    }

    public function testProcessQueueWithNoEnvelope(): void
    {
        $worker = $this->makeStubWorker(null); // returns null
        $cmd    = $this->buildCmd($worker);

        ob_start();
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->invoke($cmd, 'default');
        ob_get_clean();
        $this->assertFalse($worker->removeJobCalled);
    }

    public function testProcessQueueSuccessfulJob(): void
    {
        $envelope = $this->makeEnvelope('command', 'jobs:test');
        $worker   = $this->makeStubWorker($envelope);
        $cmd      = $this->buildCmd($worker);

        ob_start();
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->invoke($cmd, 'default');
        ob_get_clean();
        $this->assertTrue($worker->removeJobCalled);
    }

    public function testProcessQueueWithCircuitBreakerTripped(): void
    {
        // Trip the circuit breaker for the queue
        config('Jobs');
        $cb = new CircuitBreaker('queue_cb_test', 1, 3600);
        $cb->recordFailure(); // Trip the circuit

        $envelope = $this->makeEnvelope();
        $worker   = $this->makeStubWorker($envelope);
        $cmd      = $this->buildCmd($worker);

        // Change queue name to 'cb_test' which is tripped
        ob_start();
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->invoke($cmd, 'cb_test');
        ob_get_clean();

        // Circuit is open — job should not have been removed
        $this->assertFalse($worker->removeJobCalled);
        $cb->reset(); // cleanup
    }

    public function testProcessQueueWithRateLimit(): void
    {
        $cfg                            = config('Jobs');
        $cfg->queueRateLimits['rltest'] = 1;

        // Exhaust the rate limit first
        $rateLimiter = new RateLimiter();
        // Use limit=1 to exhaust it
        $rateLimiter->allow('rltest', 1); // first call succeeds

        $envelope = $this->makeEnvelope();
        $worker   = $this->makeStubWorker($envelope);
        $cmd      = $this->buildCmd($worker);

        ob_start();
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->invoke($cmd, 'rltest');
        ob_get_clean();

        // After rate limit exceeded, job should not be removed
        $this->assertFalse($worker->removeJobCalled);
        $cfg->queueRateLimits = []; // reset
    }

    public function testHandleExceptionWithWorkerAndJob(): void
    {
        $envelope = $this->makeEnvelope();
        $worker   = $this->makeStubWorker($envelope);
        $cmd      = $this->buildCmd($worker);
        $job      = new Job(job: 'command', payload: 'test');

        $ref    = new ReflectionClass($cmd);
        $m      = $ref->getMethod('handleException');
        $result = $m->invoke($cmd, new RuntimeException('test error', 500), $worker, $job);

        $this->assertFalse($result['status']);
        $this->assertSame('test error', $result['error']);
        $this->assertTrue($worker->removeJobCalled);
    }

    public function testHandleExceptionWithNullWorkerAndJob(): void
    {
        $cmd    = $this->buildCmd(null);
        $ref    = new ReflectionClass($cmd);
        $m      = $ref->getMethod('handleException');
        $result = $m->invoke($cmd, new RuntimeException('err'), null, null);

        $this->assertFalse($result['status']);
        $this->assertSame('err', $result['error']);
    }

    public function testRunWithOneTimeExecutesOnce(): void
    {
        $envelope = $this->makeEnvelope('command', 'jobs:test');
        $worker   = $this->makeStubWorker($envelope);
        $cmd      = $this->buildCmd($worker);

        ob_start();
        $cmd->run(['queue' => 'default', 'oneTime' => '1']);
        ob_get_clean();

        $this->assertTrue($worker->removeJobCalled);
    }

    public function testRunWithOneTimeNoEnvelope(): void
    {
        $worker = $this->makeStubWorker(null);
        $cmd    = $this->buildCmd($worker);

        ob_start();
        $cmd->run(['queue' => 'default', 'oneTime' => '1']);
        ob_get_clean();

        $this->assertFalse($worker->removeJobCalled);
    }

    public function testRunWithOneTimeShouldStopWorks(): void
    {
        // When shouldStop is true, the loop should break. We test by using oneTime
        // with a worker that returns null - this exercises the oneTime path.
        $worker = $this->makeStubWorker(null);
        $cmd    = $this->buildCmd($worker);

        ob_start();
        $cmd->run(['queue' => 'default', 'oneTime' => '1']);
        ob_get_clean();

        // No exceptions = pass
        $this->assertFalse($worker->removeJobCalled);
    }

    public function testRunProcessesExceptionWithinLoop(): void
    {
        $worker = new class () {
            public bool $removeJobCalled = false;
            private int $count           = 0;

            public function watch(string $queue): mixed
            {
                $this->count++;

                throw new RuntimeException('worker error');
            }

            public function removeJob(Job $job, bool $recreate): bool
            {
                $this->removeJobCalled = true;

                return true;
            }
        };

        $cmd = new class ($worker) extends QueueRunCommand {
            public function __construct(private readonly mixed $workerInstance)
            {
            }

            protected function getWorker(): mixed
            {
                return $this->workerInstance;
            }
        };

        ob_start();
        $cmd->run(['queue' => 'default', 'oneTime' => '1']);
        ob_get_clean();

        $this->assertFalse($worker->removeJobCalled); // Exception path should not remove without job
    }

    public function testRunShouldStopBreaksLoop(): void
    {
        // Test graceful shutdown: set shouldStop=true before run(), causing the loop to break
        $worker = $this->makeStubWorker(null);
        $cmd    = new class ($worker) extends QueueRunCommand {
            public function __construct(private readonly mixed $workerInstance)
            {
            }

            protected function getWorker(): mixed
            {
                return $this->workerInstance;
            }

            protected function conditionalChecks(): bool
            {
                // Always return false so we don't actually process, just let the loop see shouldStop
                return false;
            }
        };

        // Set shouldStop = true via reflection before running
        $ref  = new ReflectionClass(QueueRunCommand::class);
        $prop = $ref->getProperty('shouldStop');
        $prop->setValue($cmd, true);

        ob_start();
        $cmd->run(['queue' => 'default']); // No oneTime, relies on shouldStop to break
        ob_get_clean();

        $this->assertFalse($worker->removeJobCalled);
    }

    public function testProcessQueueWithEnvelopeHavingCreatedAt(): void
    {
        $envelope = $this->makeEnvelopeWithCreatedAt('command', 'jobs:test');
        $worker   = $this->makeStubWorker($envelope);
        $cmd      = $this->buildCmd($worker);

        ob_start();
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->invoke($cmd, 'default');
        ob_get_clean();

        // Job should have been processed and age metric observed
        $this->assertTrue($worker->removeJobCalled);
    }

    public function testRunInBackgroundReturnsEarly(): void
    {
        $worker = $this->makeStubWorker($this->makeEnvelope());
        $cmd    = $this->buildCmd($worker);

        ob_start();
        $cmd->run(['queue' => 'default', 'oneTime' => '1', 'background' => '1']);
        ob_get_clean();

        // Background mode should return before queue processing in parent process
        $this->assertFalse($worker->removeJobCalled);
    }

    public function testGetPhpBinaryReturnsString(): void
    {
        $cmd = $this->buildCmd($this->makeStubWorker(null));
        $ref = new ReflectionClass(QueueRunCommand::class);
        $m   = $ref->getMethod('getPhpBinary');
        $bin = $m->invoke($cmd);

        $this->assertIsString($bin);
        $this->assertNotSame('', $bin);
    }

    public function testProcessQueueWithLegacyEntityTriggersValidationPath(): void
    {
        $worker = new class () {
            public bool $removeJobCalled = false;

            public function watch(string $queue): mixed
            {
                return (object) ['legacy' => true];
            }

            public function removeJob(Job $job, bool $recreate): bool
            {
                $this->removeJobCalled = true;

                return true;
            }
        };

        $cmd = $this->buildCmd($worker);

        ob_start();
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->invoke($cmd, 'default');
        ob_get_clean();

        $this->assertFalse($worker->removeJobCalled);
    }

    public function testProcessQueueWithInvalidEnvelopePayloadType(): void
    {
        $worker = new class () {
            public bool $removeJobCalled = false;

            public function watch(string $queue): mixed
            {
                return new JobEnvelope(
                    id: 'bad-payload',
                    queue: 'default',
                    payload: ['not' => 'object'],
                    attempts: 0,
                );
            }

            public function removeJob(Job $job, bool $recreate): bool
            {
                $this->removeJobCalled = true;

                return true;
            }
        };

        $cmd = $this->buildCmd($worker);

        ob_start();
        $ref = new ReflectionClass($cmd);
        $m   = $ref->getMethod('processQueue');
        $m->invoke($cmd, 'default');
        ob_get_clean();

        $this->assertFalse($worker->removeJobCalled);
    }

    public function testRunWithoutOneTimeCoversSleepPath(): void
    {
        $cfg               = config('Jobs');
        $previousInterval  = $cfg->pollInterval;
        $cfg->pollInterval = 0;

        $cmd = new class () extends QueueRunCommand {
            public function __construct()
            {
            }

            protected function getWorker(): mixed
            {
                return null;
            }

            protected function processQueue(string $queue): void
            {
                // Force next loop iteration to stop after one sleep cycle.
                $ref  = new ReflectionClass(QueueRunCommand::class);
                $prop = $ref->getProperty('shouldStop');
                $prop->setValue($this, true);
            }
        };

        ob_start();
        $cmd->run(['queue' => 'default']);
        ob_get_clean();

        $cfg->pollInterval = $previousInterval;
        $ref               = new ReflectionClass(QueueRunCommand::class);
        $prop              = $ref->getProperty('shouldStop');
        $this->assertTrue((bool) $prop->getValue($cmd));
    }

    public function testCallbackNoOpHooksAreCallable(): void
    {
        $cmd = $this->buildCmd($this->makeStubWorker(null));
        $job = new Job(job: 'command', payload: 'jobs:test');
        $ref = new ReflectionClass($cmd);

        $earlyCb = $ref->getMethod('earlyCallbackChecks');
        $lateCb  = $ref->getMethod('lateCallbackChecks');

        $earlyCb->invoke($cmd, $job);
        $lateCb->invoke($cmd, $job);

        $this->assertSame('jobs:test', $job->getPayload());
    }
}
