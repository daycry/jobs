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

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\CLI;
use Daycry\Jobs\Libraries\CircuitBreaker;
use Daycry\Jobs\Libraries\RateLimiter;
use Daycry\Jobs\Queues\BackendFactory;
use Daycry\Jobs\Worker\QueueWorker;
use Throwable;

/**
 * v3 queue worker: pulls messages from the configured backend and runs them through the
 * QueueWorker pipeline (fetch -> verify -> run one attempt -> ack/nack(backoff)/abandon).
 *
 * Graceful shutdown: SIGTERM/SIGINT (POSIX) finish the current cycle and exit. A circuit
 * breaker skips a backend that is failing, and per-queue rate limits are honoured. Use
 * --once (or --max N) to bound the number of cycles (one-shot / cron-friendly).
 */
final class QueueWorkCommand extends BaseJobsCommand
{
    protected $name        = 'jobs:queue:work';
    protected $description = 'Start a v3 queue worker.';
    protected $usage       = 'jobs:queue:work [queue] [--once] [--max N] [--backend name]';
    protected $arguments   = ['queue' => 'The queue name (defaults to the first configured queue).'];
    protected $options     = [
        '--once'    => 'Process a single cycle and exit.',
        '--max'     => 'Process at most N cycles then exit (0 = unlimited).',
        '--backend' => 'Override the configured backend name.',
    ];
    private bool $shouldStop = false;

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $this->getConfig();

        $queueArg = $params['queue'] ?? $params[0] ?? CLI::getOption('queue');
        $queue    = is_string($queueArg) && $queueArg !== '' ? $queueArg : $this->firstQueue();

        $backendOption = $params['backend'] ?? CLI::getOption('backend');
        $backend       = BackendFactory::make(
            $this->config,
            is_string($backendOption) && $backendOption !== '' ? $backendOption : null,
        );
        $worker = new QueueWorker($backend);

        $maxCycles = $this->resolveMaxCycles($params);

        $this->registerSignalHandlers();
        CLI::write("[Worker] processing queue '{$queue}'" . ($maxCycles > 0 ? " (max {$maxCycles})" : ''), 'green');

        $cycles = 0;

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->shouldStop) {
                CLI::write('[Worker] graceful shutdown complete.', 'yellow');
                break;
            }

            $status = $this->processCycle($worker, $queue);
            $cycles++;

            if ($maxCycles > 0 && $cycles >= $maxCycles) {
                break;
            }

            if (in_array($status, ['empty', 'rate-limited', 'circuit-open', 'error'], true)) {
                sleep(max(1, $this->config->pollInterval));
            }
        }

        return self::SUCCESS;
    }

    private function processCycle(QueueWorker $worker, string $queue): string
    {
        $limit = $this->config->queueRateLimits[$queue] ?? 0;
        if ($limit > 0 && ! (new RateLimiter())->allow($queue, $limit)) {
            CLI::write("[Rate Limited] '{$queue}' reached {$limit} jobs/min.", 'yellow');

            return 'rate-limited';
        }

        $breaker = new CircuitBreaker('queue_' . $queue, $this->config->circuitBreakerThreshold, $this->config->circuitBreakerCooldown);
        if (! $breaker->isAvailable()) {
            CLI::write("[Circuit Open] backend for '{$queue}' temporarily unavailable.", 'red');

            return 'circuit-open';
        }

        try {
            $result = $worker->processOnce($queue);
            $breaker->recordSuccess();
        } catch (Throwable $e) {
            $breaker->recordFailure();
            CLI::error("[Worker] backend error on '{$queue}': " . $e->getMessage());

            return 'error';
        }

        if ($result->status !== 'empty') {
            $suffix = $result->error !== null ? ' - ' . $result->error : '';
            CLI::write("[{$result->status}] {$queue} (attempt {$result->attempts}){$suffix}", $result->status === 'acked' ? 'cyan' : 'yellow');
        }

        return $result->status;
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function resolveMaxCycles(array $params): int
    {
        if (array_key_exists('once', $params) || CLI::getOption('once') !== null) {
            return 1;
        }

        $max = $params['max'] ?? CLI::getOption('max');

        return is_numeric($max) ? max(1, (int) $max) : 0;
    }

    private function firstQueue(): string
    {
        $queues = $this->config->queues;
        if (is_array($queues)) {
            $first = $queues[0] ?? 'default';

            return $first !== '' ? $first : 'default';
        }

        $parts = explode(',', $queues);
        $first = trim($parts[0]);

        return $first !== '' ? $first : 'default';
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $handler = function (): void {
            $this->shouldStop = true;
            CLI::write('[Worker] stop signal received, finishing current cycle...', 'yellow');
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
