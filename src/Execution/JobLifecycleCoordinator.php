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

        // Dispatch callback job if defined
        if (method_exists($job, 'hasCallbackJob') && $job->hasCallbackJob()) {
            $this->dispatchCallbackJob($job, $finalResult);
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

    /**
     * Builds and executes or enqueues the callback job based on descriptor.
     */
    private function dispatchCallbackJob(Job $parent, ExecutionResult $result): void
    {
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
            return; // Fail silently to not break parent flow
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
        if (method_exists($child, 'source')) {
            $child->source('callback');
        }
        if (method_exists($child, 'markAsCallbackChild')) {
            $child->markAsCallbackChild((bool) ($descriptor->allowChain ?? false));
        }

        $allowChain = (bool) ($descriptor->allowChain ?? false);

        if ($child->getQueue() !== null) {
            // Enqueue: we cannot process child's callback chain now (will happen when worker executes it)
            try {
                $child->push();
            } catch (Throwable) { // silent
            }
        } else {
            // Inline execution (we can cascade if allowed)
            try {
                $childResult = $this->executor->execute($child);
                if ($allowChain && method_exists($child, 'hasCallbackJob') && $child->hasCallbackJob()) {
                    // recursive dispatch for child
                    $this->dispatchCallbackJob($child, $childResult);
                }
            } catch (Throwable) { // ignore
            }
        }
    }
}
