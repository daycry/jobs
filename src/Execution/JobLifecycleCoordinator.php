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

namespace Daycry\Jobs\Execution;

use Daycry\Jobs\Job;
use RuntimeException;
use Throwable;

/**
 * Orchestrates full job lifecycle including retries, completion strategies, and notifications.
 * Uses configured RetryPolicy and CompletionStrategy factories to drive control flow.
 * Ensures single-instance jobs acquire and release a runtime flag to avoid concurrent runs.
 */
class JobLifecycleCoordinator
{
    public function __construct(
        private RetryPolicyFactory $retryFactory = new RetryPolicyFactory(),
        private CompletionStrategyFactory $completionFactory = new CompletionStrategyFactory(),
        private ?JobExecutor $executor = null,
        /**
         * @var callable(int):void
         */
        private $sleeper = null,
    ) {
        $this->executor ??= new JobExecutor();
        $this->sleeper ??= static function (int $seconds): void { if ($seconds > 0) { sleep($seconds); } };
    }

    public function run(Job $job, ExecutionContext $ctx): LifecycleOutcome
    {
        $attempt      = 0;
        $attemptsMeta = [];
        $policy       = $this->retryFactory->for($ctx);
        $completion   = $this->completionFactory->for($ctx);

        $maxRetries   = max(0, $ctx->maxRetries);
        $finalFailure = false;
        $requeued     = false;
        $finalResult  = null;

        // Lock single instance (si aplica)
        if ($ctx->singleInstance && $job->isSingleInstance()) {
            if ($job->isRunning()) {
                throw new RuntimeException('Job already running: ' . $job->getName());
            }
            $job->saveRunningFlag();
        }

        try {
            while (true) {
                $attempt++;
                $exec = $this->safeExecute($job);

                $attemptsMeta[] = [
                    'attempt'  => $attempt,
                    'success'  => $exec->success,
                    'error'    => $exec->error,
                    'duration' => $exec->durationSeconds(),
                ];

                // Notificaciones directas con ExecutionResult
                if ($ctx->notifyOnSuccess && $exec->success && $job->shouldNotifyOnSuccess()) {
                    $job->notify($exec);
                } elseif ($ctx->notifyOnFailure && ! $exec->success && $job->shouldNotifyOnFailure()) {
                    $job->notify($exec);
                }

                if ($exec->success) {
                    $completion->onSuccess($job, $exec, $ctx);
                    $finalResult = $exec;
                    break;
                }

                // Fallo
                if ($attempt > $maxRetries) {
                    $completion->onFailure($job, $exec, $ctx, $attempt);
                    $finalResult  = $exec;
                    $finalFailure = true;
                    break;
                }

                $delay = $policy->computeDelay($attempt + 1); // Próximo intento
                if ($delay > 0) {
                    ($this->sleeper)($delay);
                }
            }
        } finally {
            if ($ctx->singleInstance && $job->isSingleInstance()) {
                $job->clearRunningFlag();
            }
        }

        if (! $finalResult) {
            // fallback improbable
            $finalResult = new ExecutionResult(false, null, 'Unknown execution state', microtime(true), microtime(true));
        }

        return new LifecycleOutcome(
            finalResult: $finalResult,
            attempts: $attempt,
            finalFailure: $finalFailure,
            requeued: $requeued,
            attemptsMeta: $attemptsMeta,
        );
    }

    private function safeExecute(Job $job): ExecutionResult
    {
        try {
            $start = microtime(true);

            return $this->executor->execute($job); // Ya retorna ExecutionResult directo
        } catch (Throwable $e) {
            $t = microtime(true);

            return new ExecutionResult(false, null, $e->getMessage(), $t, $t, null);
        }
    }
}
