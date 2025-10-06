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

namespace Daycry\Jobs\Cronjob;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Events\Events;
use CodeIgniter\I18n\Time;
use DateTime;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\ExecutionContext;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\JobLogger;
use Throwable;

/**
 * Drives execution of due cron Jobs using the unified lifecycle coordinator.
 * Handles filtering (only list), schedule checks, queue hand-off, logging and basic event emission.
 */
class JobRunner
{
    protected Scheduler $scheduler;
    protected ?Time $testTime = null;
    protected array $only     = [];
    protected $config; // Jobs config instance

    public function __construct(?Jobs $config = null)
    {
        $this->config    = $config ?: config('Jobs');
        $this->scheduler = service('scheduler');
    }

    /**
     * Execute all eligible jobs in dependency order.
     */
    public function run(): void
    {
        $order = $this->scheduler->getExecutionOrder();

        $coordinator = new JobLifecycleCoordinator();

        foreach ($order as $job) {
            if ($this->shouldSkipTask($job)) {
                $this->fire('cronjob.skipped', ['job' => $job, 'reason' => 'filter_or_schedule']);

                continue;
            }

            $this->cliWrite('Processing: ' . $job->getName(), 'green');

            // Preparar contexto de ejecución unificado
            $ctx = new ExecutionContext(
                source: 'cron',
                maxRetries: $job->getMaxRetries() ?? 0,
                notifyOnSuccess: $job->shouldNotifyOnSuccess(),
                notifyOnFailure: $job->shouldNotifyOnFailure(),
                singleInstance: $job->isSingleInstance(),
                queueName: $job->getQueue(),
                queueWorker: null,
                retryConfig: [
                    'strategy'   => $this->config->retryBackoffStrategy,
                    'base'       => $this->config->retryBackoffBase,
                    'multiplier' => $this->config->retryBackoffMultiplier,
                    'jitter'     => $this->config->retryBackoffJitter,
                    'max'        => $this->config->retryBackoffMax,
                ],
                eventsEnabled: $this->config->enableEvents ?? true,
                meta: [],
            );

            // Caso especial: si el job está configurado con queue, solo encolar
            if ($job->getQueue() !== null) {
                $job->push();
                $this->cliWrite('Enqueued: ' . $job->getName() . ' to queue ' . $job->getQueue(), 'blue');

                continue;
            }

            // Ejecutar ciclo completo
            $outcome = $coordinator->run($job, $ctx);

            $exec = $outcome->finalResult; // ExecutionResult

            // Logging (nuevo JobLogger)
            $logger = new JobLogger();
            $logger->start(date('Y-m-d H:i:s', (int) $exec->startedAt));
            $logger->end(date('Y-m-d H:i:s', (int) $exec->endedAt));
            $logger->log($job, $exec, $this->testTime);

            // Notificaciones: el Coordinator ya envía (por ahora aún usa shim). Aquí opcionalmente podemos disparar eventos.
            if ($exec->success) {
                $this->fire('cronjob.succeeded', [
                    'job'      => $job,
                    'attempts' => $outcome->attempts,
                    'duration' => $exec->durationSeconds(),
                ]);
            } else {
                $this->fire('cronjob.failed', [
                    'job'      => $job,
                    'error'    => $exec->error,
                    'attempts' => $outcome->attempts,
                ]);
                $this->cliWrite('Failed: ' . $job->getName() . ' - ' . ($exec->error ?? 'Unknown error'), 'red');
            }

            $this->cliWrite('Finished: ' . $job->getName() . ' (' . ($exec->success ? 'SUCCESS' : 'FAIL') . ')', $exec->success ? 'cyan' : 'red');

            sleep($this->config->defaultTimeout ?? 0);
        }
    }

    /**
     * Inject a frozen test time (string accepted by DateTime) for deterministic schedule evaluation.
     */
    public function withTestTime(string $time): self
    {
        $this->testTime = Time::createFromInstance(new DateTime($time));

        return $this;
    }

    /**
     * Restrict execution to given job names (whitelist).
     */
    public function only(array $jobs = []): self
    {
        $this->only = $jobs;

        return $this;
    }

    /**
     * Processes a single job and returns the result.
     *
     * @param Job $job
     *
     * @return ?Result
     */
    // Removed obsolete single-job execution method; Coordinator manages lifecycle.

    /**
     * Compute delay (seconds) before next retry based on config and attempt number.
     * Attempt is 1-based (i.e. first run = 1, retries >= 2).
     */
    protected function computeBackoffDelay(int $attempt): int
    {
        if ($attempt <= 1 || $this->config->retryBackoffStrategy === 'none') {
            return 0;
        }

        $base       = max(1, $this->config->retryBackoffBase);
        $max        = $this->config->retryBackoffMax;
        $strategy   = $this->config->retryBackoffStrategy;
        $multiplier = $this->config->retryBackoffMultiplier > 0 ? $this->config->retryBackoffMultiplier : 2.0;

        // Calcular delay según estrategia
        $delay = match ($strategy) {
            'fixed'       => $base,
            'exponential' => (int) round($base * ($multiplier ** ($attempt - 2))),
            default       => $base,
        };

        // Limitar al máximo permitido
        $delay = min($delay, $max);

        // Aplicar jitter (±15%)
        if ($this->config->retryBackoffJitter) {
            $jitterRange = max(1, (int) round($delay * 0.15));
            $delay       = max(1, $delay + random_int(-$jitterRange, $jitterRange));
        }

        return $delay;
    }

    /**
     * Mark a job as running (single-instance guard).
     */
    private function markRunningJob(Job $job): void
    {
        if ($job->isRunning() && $job->isSingleInstance()) {
            throw JobException::TaskAlreadyRunningException($job->getName());
        }

        if ($job->saveRunningFlag()) {
            $this->cliWrite('Marked as running: ' . $job->getName(), 'yellow');
        }
    }

    /**
     * Conditional CLI output (suppressed in tests and non-CLI contexts).
     */
    private function cliWrite(string $text, ?string $foreground = null): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            return;
        }
        if (! is_cli()) {
            return;
        }
        CLI::write('[' . date('Y-m-d H:i:s') . '] ' . $text, $foreground);
    }

    /**
     * Determine if a job should be skipped due to filters or schedule.
     *
     * @param mixed $job
     */
    private function shouldSkipTask($job): bool
    {
        return (! empty($this->only) && ! in_array($job->getName(), $this->only, true))
               || (! $job->shouldRun($this->testTime) && empty($this->only));
    }

    /**
     * Fire internal event if enabled. Exceptions from listeners are swallowed (logged at warning level).
     *
     * @param array<string,mixed> $payload
     */
    private function fire(string $event, array $payload = []): void
    {
        if (! ($this->config->enableEvents ?? true)) {
            return;
        }

        try {
            Events::trigger($event, $payload);
        } catch (Throwable $e) {
            log_message('warning', 'CronJob event listener error on ' . $event . ': ' . $e->getMessage());
        }
    }
}
