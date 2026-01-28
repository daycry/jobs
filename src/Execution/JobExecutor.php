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

use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\JobLogger;
use Throwable;

/**
 * Executes a single Job handler lifecycle: beforeRun -> handle -> afterRun.
 * Produces an immutable ExecutionResult capturing success/output/error and timing.
 * Handlers should return raw scalar/array/object data; absence of exception implies success.
 */
class JobExecutor
{
    public function execute(Job $job): ExecutionResult
    {
        $start  = microtime(true);
        $logger = null;
        if (config('Jobs')->logPerformance) {
            $logger = new JobLogger();
            $logger->start(date('Y-m-d H:i:s'));
        }
        $bufferActive = false;

        try {
            $mapping = config('Jobs')->jobs;
            $class   = $mapping[$job->getJob()] ?? null;
            if (! $class || ! is_subclass_of($class, Job::class)) {
                throw JobException::forInvalidJob($job->getJob());
            }
            /** @var JobInterface $handler */
            $handler = new $class();

            $job = $handler->beforeRun($job);
            ob_start();
            $bufferActive = true;
            $returned     = $handler->handle($job->getPayload()); // Handler arbitrary return (scalar|array|object|null)
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
            if ($logger) {
                $logger->end(date('Y-m-d H:i:s'));
                $logger->log($job, $executionResult, null);
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
            $executionResult = new ExecutionResult(false, null, $e->getMessage(), $start, $t, null);
            if ($logger) {
                $logger->end(date('Y-m-d H:i:s'));
                $logger->log($job, $executionResult, null);
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

        return json_encode($data);
    }
}
