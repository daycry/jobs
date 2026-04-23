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

use Closure;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\JobLogger;
use RuntimeException;
use Throwable;

/**
 * Orchestrates full job lifecycle including retries, notifications, and timeout protection.
 * Uses configured RetryPolicy to drive control flow.
 * Ensures single-instance jobs acquire and release a runtime flag to avoid concurrent runs.
 */
class JobLifecycleCoordinator
{
    private const MAX_CALLBACK_DEPTH = 5;

    private $sleeper;

    public function __construct(
        /**
         * @var callable(int):void
         */
        $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    public function run(Job $job, string $source = 'cron'): LifecycleOutcome
    {
        $attemptsMeta = [];

        // Read retry config inline - use direct config() in coordinator to respect test changes
        $cfg    = config(Jobs::class) ?? new Jobs();
        $policy = new RetryPolicyFixed(
            base: $cfg->retryBackoffBase,
            strategy: $cfg->retryBackoffStrategy,
            multiplier: $cfg->retryBackoffMultiplier,
            max: $cfg->retryBackoffMax,
            jitter: $cfg->retryBackoffJitter,
        );

        $maxRetries   = max(0, $job->getMaxRetries() ?? 0);
        $finalFailure = false;
        $requeued     = false;
        $finalResult  = null;

        // Use persistent attempt counter from job instead of local counter
        $persistentAttempt = $job->getAttempt();

        // Lock single instance (si aplica)
        if ($job->isSingleInstance()) {
            if ($job->isRunning()) {
                throw new RuntimeException('Job already running: ' . $job->getName());
            }
            $job->saveRunningFlag();
        }

        try {
            while (true) {
                $persistentAttempt++;

                // Determine effective timeout for this job.
                // Priority: job-specific timeout (Job::getTimeout()), then config defaultTimeout.
                // If neither is set, treat as unlimited (0). Do NOT apply a global "cap" here.
                $jobSpecific    = $job->getTimeout();
                $defaultTimeout = $cfg->defaultTimeout ?? null;

                if ($jobSpecific !== null) {
                    $timeout = $jobSpecific;
                } elseif ($defaultTimeout !== null) {
                    $timeout = (int) $defaultTimeout;
                } else {
                    // Unlimited by default if nothing specified
                    $timeout = 0;
                }

                $exec = ($timeout > 0)
                    ? $this->safeExecuteWithTimeout($job, $timeout)
                    : $this->safeExecute($job);

                $attemptsMeta[] = [
                    'attempt'  => $persistentAttempt,
                    'success'  => $exec->success,
                    'error'    => $exec->error,
                    'duration' => $exec->durationSeconds(),
                ];

                // Notificaciones directas con ExecutionResult
                if ($exec->success && $job->shouldNotifyOnSuccess()) {
                    $job->notify($exec);
                } elseif (! $exec->success && $job->shouldNotifyOnFailure()) {
                    $job->notify($exec);
                }

                if ($exec->success) {
                    // Success: no extra action needed (completion handled by RequeueHelper)
                    $finalResult = $exec;
                    break;
                }

                // Fallo - check against persistent counter
                if ($persistentAttempt > $maxRetries) {
                    // Final failure: no extra action needed (completion handled by RequeueHelper)
                    $finalResult  = $exec;
                    $finalFailure = true;
                    break;
                }

                $delay = $policy->computeDelay($persistentAttempt + 1); // Próximo intento
                if ($delay > 0) {
                    ($this->sleeper)($delay);
                }
            }
        } finally {
            if ($job->isSingleInstance()) {
                $job->clearRunningFlag();
            }
        }

        // Dispatch callback job if defined
        if ($job->hasCallbackJob()) {
            $this->dispatchCallbackJob($job, $finalResult);
        }

        return new LifecycleOutcome(
            finalResult: $finalResult,
            attempts: $persistentAttempt,
            finalFailure: $finalFailure,
            requeued: $requeued,
            attemptsMeta: $attemptsMeta,
        );
    }

    private function safeExecute(Job $job): ExecutionResult
    {
        try {
            return $this->executeJobInternal($job);
        } catch (Throwable $e) {
            $t = microtime(true);

            return new ExecutionResult(false, null, $e->getMessage(), $t, $t);
        }
    }

    private function executeJobInternal(Job $job): ExecutionResult
    {
        $start  = microtime(true);
        $cfg    = config(Jobs::class) ?? new Jobs();
        $logger = null;
        if ($cfg->logPerformance) {
            $logger = new JobLogger();
            $logger->start(date('Y-m-d H:i:s'));
        }
        $bufferActive = false;

        try {
            $mapping = $cfg->jobs;
            $class   = $mapping[$job->getJob()] ?? null;
            if (! $class || ! is_subclass_of($class, Job::class)) {
                throw JobException::forInvalidJob($job->getJob());
            }
            /** @var JobInterface $handler */
            $handler = new $class();

            $job = $handler->beforeRun($job);
            ob_start();
            $bufferActive = true;

            // Build middleware pipeline wrapping the handler execution
            $middlewareStack = $job->getMiddleware();
            $core            = static fn (Job $j) => $handler->handle($j->getPayload());
            $pipeline        = array_reduce(
                array_reverse($middlewareStack),
                static fn (callable $next, callable $mw) => static fn (Job $j) => $mw($j, $next),
                $core,
            );
            $returned = $pipeline($job);

            $buffer       = ob_get_clean();
            $bufferActive = false;

            if ($buffer === '' || $buffer === false) {
                $buffer = null;
            }

            // Interpret return as success unless an exception was thrown.
            $success = true;
            $data    = $returned;

            // Merge captured buffer with returned data when meaningful
            if ($buffer !== null) {
                if ($data === null) {
                    $data = $buffer;
                } elseif (is_string($data) && $buffer !== '') {
                    $separator = str_starts_with($buffer, "\n") ? '' : "\n";
                    $data .= $separator . $buffer;
                }
            }

            $job             = $handler->afterRun($job);
            $end             = microtime(true);
            $executionResult = new ExecutionResult(
                success: $success,
                output: $success ? $this->normalizeOutput($data) : null,
                error: $success ? null : (is_scalar($data) ? (string) $data : json_encode($data)),
                startedAt: $start,
                endedAt: $end,
                handlerClass: $class,
            );
            if ($logger instanceof JobLogger) {
                $logger->end(date('Y-m-d H:i:s'));
                $logger->log($job, $executionResult);
            }

            return $executionResult;
        } catch (Throwable $e) {
            if ($bufferActive && ob_get_level() > 0) {
                try {
                    ob_end_clean();
                } catch (Throwable) {
                }
            }
            $t               = microtime(true);
            $executionResult = new ExecutionResult(false, null, $e->getMessage(), $start, $t);
            if ($logger instanceof JobLogger) {
                $logger->end(date('Y-m-d H:i:s'));
                $logger->log($job, $executionResult);
            }

            return $executionResult;
        }
    }

    private function normalizeOutput(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }
        if (is_scalar($data)) {
            return (string) $data;
        }

        $encoded = json_encode($data);

        return $encoded !== false ? $encoded : null;
    }

    /**
     * Execute job with timeout protection.
     */
    private function safeExecuteWithTimeout(Job $job, int $timeout): ExecutionResult
    {
        if ($timeout <= 0) {
            return $this->safeExecute($job); // No timeout
        }

        $startTime = time();
        $result    = null;
        $timedOut  = false;

        // Fork execution check using pcntl if available
        if (function_exists('pcntl_alarm')) {
            // Register alarm handler
            pcntl_signal(SIGALRM, static function () use (&$timedOut): void {
                $timedOut = true;
            });
            pcntl_alarm($timeout);

            try {
                $result = $this->safeExecute($job);
                pcntl_alarm(0); // Cancel alarm
            } catch (Throwable $e) {
                pcntl_alarm(0);

                throw $e;
            }

            if ($timedOut) {
                throw JobException::forJobTimeout($job->getName(), $timeout);
            }
        } else {
            // Fallback: simple time check (less accurate, no kill)
            $result = $this->safeExecute($job);

            if (time() - $startTime > $timeout) {
                log_message('warning', "Job {$job->getName()} exceeded timeout of {$timeout}s (no pcntl available for hard kill)");
            }
        }

        return $result;
    }

    /**
     * Builds and executes or enqueues the callback job based on descriptor.
     */
    private function dispatchCallbackJob(Job $parent, ExecutionResult $result, int $depth = 0): void
    {
        if ($depth >= self::MAX_CALLBACK_DEPTH) {
            log_message('warning', 'JobLifecycleCoordinator: max callback chain depth (' . self::MAX_CALLBACK_DEPTH . ') reached for job: ' . $parent->getName());

            return;
        }

        $descriptor = $parent->getCallbackDescriptor();
        if (! $descriptor) {
            return;
        }
        // Filter already normalized to: always|success|failure
        $filter = $descriptor->filter ?? 'always';
        if ($filter === 'success' && ! $result->success) {
            return;
        }
        if ($filter === 'failure' && $result->success) {
            return;
        }

        // Build child job via user builder
        try {
            $builder = $descriptor->builder;
            $child   = $builder($parent);
        } catch (Throwable $e) {
            log_message('error', 'JobLifecycleCoordinator: callback builder failed for job ' . $parent->getName() . ' — ' . $e->getMessage());

            return; // Fail gracefully to not break parent flow
        }
        if (! $child instanceof Job) {
            return; // Invalid builder return
        }

        // Inherit meta into payload if requested
        $inherit = $descriptor->inherit ?? [];
        $meta    = [
            'parentStatus' => $result->success,
        ];
        if (in_array('output', $inherit, true)) {
            $meta['parentOutput'] = $result->output;
        }
        if (in_array('error', $inherit, true)) {
            $meta['parentError'] = $result->error;
        }
        if (in_array('attempts', $inherit, true)) {
            $meta['parentAttempts'] = $parent->getAttempt();
        }
        if (in_array('name', $inherit, true)) {
            $meta['parentName'] = $parent->getName();
        }
        if (in_array('source', $inherit, true)) {
            $meta['parentSource'] = $parent->getSource();
        }

        // Attempt to merge meta into child payload (if array/object)
        $payload = $child->getPayload();
        if ($payload instanceof Closure) {
            // Cannot inject meta into a closure payload; skip meta merge.
        } elseif (is_array($payload)) {
            $payload['meta'] = isset($payload['meta']) && is_array($payload['meta'])
                ? $payload['meta'] + $meta
                : $meta;
        } elseif (is_object($payload) && ! ($payload instanceof Closure)) {
            foreach ($meta as $k => $v) {
                try {
                    $payload->{$k} = $v;
                } catch (Throwable) { // ignore
                }
            }
        } else {
            // Wrap scalar/callable/closure into array structure
            $payload = [
                'data' => $payload,
                'meta' => $meta,
            ];
        }

        // Replace modified payload directly (child preserves queue & configuration)
        try {
            $child->setPayload($payload);
        } catch (Throwable) {
            // ignore
        }

        // Mark origin
        $child->source('callback');
        $child->markAsCallbackChild((bool) ($descriptor->allowChain ?? false));

        $allowChain = (bool) ($descriptor->allowChain ?? false);

        if ($child->getQueue() !== null) {
            // Enqueue: we cannot process child's callback chain now (will happen when worker executes it)
            try {
                $child->push();
            } catch (Throwable $e) {
                log_message('error', 'JobLifecycleCoordinator: failed to enqueue callback job — ' . $e->getMessage());
            }
        } else {
            // Inline execution (we can cascade if allowed)
            try {
                $childResult = $this->executeJobInternal($child);
                if ($allowChain && $child->hasCallbackJob()) {
                    // recursive dispatch for child with depth tracking
                    $this->dispatchCallbackJob($child, $childResult, $depth + 1);
                }
            } catch (Throwable $e) {
                log_message('error', 'JobLifecycleCoordinator: inline callback execution failed — ' . $e->getMessage());
            }
        }
    }
}
