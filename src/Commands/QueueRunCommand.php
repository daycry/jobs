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

use Daycry\Jobs\Metrics\MetricsCollectorInterface;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;
use DateTimeInterface;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\CircuitBreaker;
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\Libraries\RateLimiter;
use Daycry\Jobs\Metrics\Metrics;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RequeueHelper;
use RuntimeException;
use Throwable;

/**
 * Long-running (or one-shot) queue worker command.
 * Pulls messages from the configured queue backend, executes them via lifecycle coordinator,
 * and applies basic retry (requeue) fallback when failures occur.
 */
class QueueRunCommand extends BaseJobsCommand
{
    protected $name                       = 'jobs:queue:run';
    protected $description                = 'Start queue worker.';
    protected $usage                      = 'queue:run <queue> [Options]';
    protected $arguments                  = ['queue' => 'The queue name.'];
    protected $options                    = ['--oneTime' => 'Only executes one time.', '--background' => 'Run the worker in background.'];
    protected bool $locked                = false;
    private ?RequeueHelper $requeueHelper = null;
    private bool $shouldStop              = false;
    private int $iterationCount           = 0;

    protected function earlyChecks(Job $job): void
    {
    }

    protected function lateChecks(Job $job): void
    {
    }

    protected function earlyCallbackChecks(Job $job): void
    {
    }

    protected function lateCallbackChecks(Job $job): void
    {
    }

    protected function conditionalChecks(): bool
    {
        return true;
    }

    public function run(array $params): void
    {
        $queue      = $params['queue'] ?? $params[0] ?? CLI::getOption('queue');
        $oneTime    = array_key_exists('oneTime', $params) ? true : CLI::getOption('oneTime');
        $background = array_key_exists('background', $params) ? true : CLI::getOption('background');

        // Spawn background child and exit parent if requested (avoid respawn with --noBackground)
        if ($background) {
            $phpBin    = $this->getPhpBinary();
            $sparkPath = ROOTPATH . 'spark';

            $args = escapeshellarg('--queue') . ' ' . escapeshellarg($queue);
            if ($oneTime) {
                $args .= ' ' . escapeshellarg('--oneTime');
            }

            if (str_starts_with(strtolower(PHP_OS), strtolower('WIN'))) {
                // Windows: use start /B and redirect to NUL
                $cmd    = sprintf('%s %s %s %s', escapeshellarg($phpBin), escapeshellarg($sparkPath), escapeshellarg($this->name), $args);
                $winCmd = 'start "" /B ' . $cmd . ' > NUL 2>&1';
                pclose(popen($winCmd, 'r'));
            } else {
                // POSIX: use nohup and redirect to /dev/null, detach with &
                $cmd      = sprintf('%s %s %s %s', escapeshellarg($phpBin), escapeshellarg($sparkPath), escapeshellarg($this->name), $args);
                $posixCmd = 'nohup ' . $cmd . ' > /dev/null 2>&1 &';
                exec($posixCmd);
            }

            return;
        }

        if (empty($queue)) {
            $queue = CLI::prompt(lang('Queue.insertQueue'), config('Jobs')->queues, 'required');
        }

        // Register signal handlers for graceful shutdown (POSIX only)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function (): void {
                $this->shouldStop = true;
                CLI::write('[Worker] SIGTERM received, finishing current job...', 'yellow');
            });
            pcntl_signal(SIGINT, function (): void {
                $this->shouldStop = true;
                CLI::write('[Worker] SIGINT received, finishing current job...', 'yellow');
            });
        }

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->shouldStop) {
                CLI::write('[Worker] Graceful shutdown complete.', 'yellow');
                break;
            }

            if ($this->conditionalChecks()) {
                $this->processQueue($queue);
                $this->maintenanceTick();

                if ($oneTime) {
                    return;
                }

                $this->idleSleep();
            }
        }
    }

    /**
     * Periodic worker-loop maintenance:
     *  - Reset in-memory metrics every 1 000 iterations to bound memory growth (F19).
     *  - Ping the database connection every 100 iterations and reconnect if needed (F23).
     */
    private function maintenanceTick(): void
    {
        $this->iterationCount++;

        if ($this->iterationCount % 1000 === 0) {
            $metrics = Metrics::get();
            if ($metrics instanceof MetricsCollectorInterface && method_exists($metrics, 'reset')) {
                $metrics->reset();
            }
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if ($this->iterationCount % 100 === 0) {
            $this->ensureDatabaseConnection();
        }
    }

    /**
     * Cheap SELECT 1 against the configured DB connection. On failure the connection is
     * reset so the next query reconnects. Important for long-lived workers behind MySQL
     * wait_timeout / connection idle eviction.
     */
    private function ensureDatabaseConnection(): void
    {
        try {
            $cfg   = config('Jobs');
            $group = $cfg->database['group'] ?? null;
            $db    = Database::connect($group);
            // simpleQuery returns a resource on success and false on failure across drivers.
            $result = $db->simpleQuery('SELECT 1');
            if ($result === false) {
                throw new RuntimeException('SELECT 1 failed (driver returned false).');
            }
        } catch (Throwable $e) {
            log_message('warning', 'QueueRunCommand: DB ping failed, reconnecting — ' . $e->getMessage());

            try {
                $cfg   = config('Jobs');
                $group = $cfg->database['group'] ?? null;
                Database::connect($group, false)->reconnect();
            } catch (Throwable $reconnect) {
                log_message('error', 'QueueRunCommand: DB reconnect failed — ' . $reconnect->getMessage());
            }
        }
    }

    /**
     * Sleep between polling cycles, unless the active backend uses blocking
     * fetch semantics (Redis BRPOPLPUSH, Beanstalk reserve_with_timeout) in
     * which case watch() already absorbed the wait.
     */
    private function idleSleep(): void
    {
        $cfg = config('Jobs');

        if (($cfg->blockingFetch ?? false) === true && in_array($cfg->worker, ['redis', 'beanstalk'], true)) {
            return;
        }

        sleep($cfg->pollInterval);
    }

    protected function processQueue(string $queue): void
    {
        $response = [];
        $metrics  = Metrics::get();
        $this->requeueHelper ??= new RequeueHelper($metrics);

        // Rate limiting check
        $config    = config('Jobs');
        $rateLimit = $config->queueRateLimits[$queue] ?? 0;

        if ($rateLimit > 0) {
            $rateLimiter = new RateLimiter();
            if (! $rateLimiter->allow($queue, $rateLimit)) {
                // Rate limit exceeded, skip processing this cycle
                CLI::write("[Rate Limited] Queue '{$queue}' has reached limit of {$rateLimit} jobs/minute", 'yellow');

                return;
            }
        }

        // Circuit breaker check
        $breaker = new CircuitBreaker(
            'queue_' . $queue,
            $config->circuitBreakerThreshold,
            $config->circuitBreakerCooldown,
        );

        if (! $breaker->isAvailable()) {
            CLI::write("[Circuit Open] Backend for '{$queue}' is temporarily unavailable, skipping.", 'red');

            return;
        }

        Services::resetSingle('request');
        Services::resetSingle('response');

        try {
            $worker      = $this->getWorker();
            $queueEntity = $worker->watch($queue);
            $breaker->recordSuccess();

            if ($queueEntity === null) {
                // No available job for this queue at this time.
                return;
            }

            if ($queueEntity !== null) {
                $metrics->increment('jobs_fetched', 1, ['queue' => $queue]);
                $this->locked = true;
                if (! ($queueEntity instanceof JobEnvelope)) {
                    throw JobException::validationError('Legacy queue entity unsupported (expecting JobEnvelope).');
                }
                $decoded = $queueEntity->payload;
                if (! is_object($decoded)) {
                    throw JobException::validationError('Invalid envelope payload format.');
                }
                $job = Job::fromQueueRecord($decoded);
                // Inject backend ID into job instance for context availability
                $job->setJobId($queueEntity->id);

                $this->earlyChecks($job);

                $this->lateChecks($job); // todavía antes de ejecutar? mantener orden original

                $coordinator = new JobLifecycleCoordinator();
                $startExec   = microtime(true);
                $outcome     = $coordinator->run($job, 'queue');
                $latency     = microtime(true) - $startExec;
                $exec        = $outcome->finalResult;
                $response    = [
                    'status'     => $exec->success,
                    'statusCode' => $exec->success ? 200 : 500,
                    'data'       => $exec->output,
                    'error'      => $exec->success ? null : $exec->error,
                ];

                // Execution completed; outcome handled below.

                // Finalización: usar completion strategy ya ejecutada dentro del coordinator.
                // Remoción/requeue ya la maneja la estrategia QueueCompletionStrategy (si lo configuramos). Si aún no, aplicamos fallback:
                $this->requeueHelper->finalize($job, $queueEntity, static fn ($j, $r) => $worker->removeJob($j, $r), $exec->success);
                if ($queueEntity->createdAt instanceof DateTimeInterface) {
                    $age = microtime(true) - $queueEntity->createdAt->getTimestamp();
                    $metrics->observe('jobs_age_seconds', $age, ['queue' => $queue]);
                }
                $metrics->observe('jobs_exec_seconds', $latency, ['queue' => $queue]);
            }
        } catch (Throwable $e) {
            $breaker->recordFailure();
            $response = $this->handleException($e, $worker ?? null, $job ?? null);
        }

        $this->locked = false;
        unset($job, $queueEntity);
    }

    protected function getWorker()
    {
        return QueueManager::instance()->getDefault();
    }

    protected function handleException($e, $worker, $job): array
    {
        $response['statusCode'] = $e->getCode();
        $response['error']      = $e->getMessage();
        $response['status']     = false;

        if ($worker && $job) {
            $worker->removeJob($job, true);
        }

        $this->showError($e);

        return $response;
    }

    private function getPhpBinary(): string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        return (string) (env('PHP_BINARY_PATH') ?? 'php');
    }
}
