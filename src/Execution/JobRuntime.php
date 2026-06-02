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

use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Handlers\HandlerRegistry;
use Daycry\Jobs\Handlers\JobHandlerInterface;
use Daycry\Jobs\V2\JobDefinition;
use Throwable;

/**
 * Executes a job ONE time and returns an {@see ExecutionResult}.
 *
 * This is the v3 replacement for {@see JobLifecycleCoordinator}. Crucially it does NOT
 * loop or sleep for retries: a single attempt runs here, and the worker decides whether to
 * requeue with backoff (via QueueBackend::nack) — which removes the legacy double-retry
 * (coordinator loop + RequeueHelper) that consumed maxRetries twice and blocked the worker.
 *
 * Responsibilities: resolve the handler (with per-queue allowlist), capture output, apply a
 * real timeout that interrupts the work, and turn any Throwable into a failed result.
 */
final class JobRuntime
{
    private readonly Jobs $config;
    private readonly Timeout $timeout;
    private readonly HandlerRegistry $registry;

    public function __construct(?Timeout $timeout = null, ?HandlerRegistry $registry = null, ?Jobs $config = null)
    {
        $this->config   = $config ?? config('Jobs');
        $this->timeout  = $timeout ?? new Timeout();
        $this->registry = $registry ?? new HandlerRegistry($this->config);
    }

    public function run(JobDefinition $definition, JobContext $context): ExecutionResult
    {
        $start = microtime(true);

        try {
            $handler = $this->registry->resolveForQueue($definition->handler, $context->queue ?? 'default');
        } catch (Throwable $e) {
            return new ExecutionResult(false, null, $e->getMessage(), $start, microtime(true));
        }

        $timeoutSeconds = $this->resolveTimeout($definition);
        $handlerClass   = $handler::class;
        $bufferActive   = false;

        try {
            $handler->beforeRun($context);

            ob_start();
            $bufferActive = true;

            $returned = $this->timeout->run(
                $timeoutSeconds,
                static fn (): mixed => $handler->handle($context),
                $context->name ?? $definition->handler,
            );

            $buffer       = ob_get_clean();
            $bufferActive = false;

            $result = new ExecutionResult(
                success: true,
                output: $this->normalizeOutput($returned, $buffer === false ? null : $buffer),
                error: null,
                startedAt: $start,
                endedAt: microtime(true),
                handlerClass: $handlerClass,
            );

            $this->safeAfterRun($handler, $context, $result);

            return $result;
        } catch (Throwable $e) {
            if ($bufferActive && ob_get_level() > 0) {
                ob_end_clean();
            }

            $result = new ExecutionResult(false, null, $e->getMessage(), $start, microtime(true), $handlerClass);
            $this->safeAfterRun($handler, $context, $result);

            return $result;
        }
    }

    private function resolveTimeout(JobDefinition $definition): int
    {
        if ($definition->timeout !== null) {
            return max(0, $definition->timeout);
        }

        $default = $this->config->defaultTimeout;

        return $default !== null ? max(0, $default) : 0;
    }

    private function safeAfterRun(JobHandlerInterface $handler, JobContext $context, ExecutionResult $result): void
    {
        try {
            $handler->afterRun($context, $result);
        } catch (Throwable) {
            // afterRun is a best-effort hook; never let it change the recorded outcome.
        }
    }

    private function normalizeOutput(mixed $returned, ?string $buffer): ?string
    {
        $data = $returned;

        if ($buffer !== null && $buffer !== '') {
            if ($data === null) {
                $data = $buffer;
            } elseif (is_string($data)) {
                $separator = str_starts_with($buffer, "\n") ? '' : "\n";
                $data .= $separator . $buffer;
            }
        }

        if ($data === null) {
            return null;
        }
        if (is_scalar($data)) {
            return (string) $data;
        }

        $encoded = json_encode($data);

        return $encoded === false ? null : $encoded;
    }
}
