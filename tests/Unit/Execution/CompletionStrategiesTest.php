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

use Daycry\Jobs\Execution\CronCompletionStrategy;
use Daycry\Jobs\Execution\ExecutionContext;
use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Execution\QueueCompletionStrategy;
use Daycry\Jobs\Interfaces\WorkerInterface;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class CompletionStrategiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                 = config('Jobs');
        $cfg->logPerformance = false;
        $cfg->log            = 'file';
    }

    private function makeContext(string $source, ?WorkerInterface $worker = null, int $maxRetries = 0): ExecutionContext
    {
        return new ExecutionContext(
            source: $source,
            maxRetries: $maxRetries,
            notifyOnSuccess: false,
            notifyOnFailure: false,
            singleInstance: false,
            queueName: $source === 'queue' ? 'default' : null,
            queueWorker: $worker,
            retryConfig: [
                'strategy'   => 'fixed',
                'base'       => 1,
                'multiplier' => 2.0,
                'jitter'     => false,
                'max'        => 2,
            ],
            eventsEnabled: false,
            meta: [],
        );
    }

    public function testCronStrategyDoesNotCallRemoveJob(): void
    {
        $strategy = new CronCompletionStrategy();
        $job      = new Job(job: 'closure', payload: static fn () => 'ok');
        $job->named('cron_task');
        $ctx = $this->makeContext('cron', null);
        // Fake execution result
        $result = new ExecutionResult(true, 'ok', null, microtime(true), microtime(true), 'closure');
        // Should simply do nothing (no exception)
        $strategy->onSuccess($job, $result, $ctx);
        $this->assertTrue(true); // If we reach here, pass
    }

    public function testQueueStrategyCallsRemoveJobOnSuccess(): void
    {
        $calls  = [];
        $worker = new class ($calls) implements WorkerInterface {
            public array $log = [];

            public function __construct(&$log)
            {
                $this->log = &$log;
            }

            public function watch(string $queue): void
            {
            }

            public function removeJob(Job $job, bool $recreate = false): bool
            {
                $this->log[] = ['name' => $job->getName(), 'recreate' => $recreate, 'success' => true];

                return true;
            }
        };
        $strategy = new QueueCompletionStrategy();
        $job      = new Job(job: 'closure', payload: static fn () => 'ok');
        $job->named('queue_task_success');
        $ctx    = $this->makeContext('queue', $worker);
        $result = new ExecutionResult(true, 'ok', null, microtime(true), microtime(true), 'closure');
        $strategy->onSuccess($job, $result, $ctx);
        $this->assertCount(1, $worker->log);
        $this->assertFalse($worker->log[0]['recreate']);
    }

    public function testQueueStrategyCallsRemoveJobWithRecreateOnFailure(): void
    {
        $calls  = [];
        $worker = new class ($calls) implements WorkerInterface {
            public array $log = [];

            public function __construct(&$log)
            {
                $this->log = &$log;
            }

            public function watch(string $queue): void
            {
            }

            public function removeJob(Job $job, bool $recreate = false): bool
            {
                $this->log[] = ['name' => $job->getName(), 'recreate' => $recreate, 'success' => false];

                return true;
            }
        };
        $strategy = new QueueCompletionStrategy();
        $job      = new Job(job: 'closure', payload: static fn () => 'fail');
        $job->named('queue_task_fail');
        $ctx    = $this->makeContext('queue', $worker, maxRetries: 0);
        $result = new ExecutionResult(false, null, 'err', microtime(true), microtime(true), 'closure');
        $strategy->onFailure($job, $result, $ctx, 1);
        $this->assertCount(1, $worker->log);
        $this->assertTrue($worker->log[0]['recreate']);
    }
}
