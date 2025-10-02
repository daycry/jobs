<?php

declare(strict_types=1);

namespace Daycry\Jobs\Cronjob;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Exceptions\ExceptionInterface;
use CodeIgniter\Exceptions\RuntimeException;
use Config\Services;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Job;
use Daycry\Jobs\Execution\JobExecutor;
use CodeIgniter\I18n\Time;
use DateTime;
use Daycry\Jobs\Result;
use Throwable;
use CodeIgniter\Events\Events;

/**
 * Class Scheduler
 *
 * Handles the registration and management of scheduled jobs.
 */
class JobRunner
{
    protected Scheduler $scheduler;
    protected ?Time $testTime = null;
    protected array $only = [];
    protected $config; // Jobs config instance

    public function __construct(?Jobs $config = null)
    {
        $this->config    = $config ?: config('Jobs');
        $this->scheduler = service('scheduler');
    }

    public function run(): void
    {
        $order      = $this->scheduler->getExecutionOrder();

        foreach ($order as $job) {
            $retry      = 0;
            $error        = null;
            $maxRetries = $job->getMaxRetries() ?? 0;
            $attempt      = 0;

            if ($this->shouldSkipTask($job)) {
                $this->fire('cronjob.skipped', [
                    'job'    => $job,
                    'reason' => 'filter_or_schedule',
                ]);
                continue;
            }

            do {
                $retry ++;
                $attemptStart = microtime(true);

                try {
                    if($job->isEnabled() === true) {
                        $result   = $this->processJob($job);
                    }
                } catch (ExceptionInterface $e) {
                    $result = new Result();
                    $result->setSuccess(false);
                    $result->setData($e->getMessage());

                    $this->cliWrite('Failed: ' . $job->getName() . ' - ' . $e->getMessage(), 'red');
                    $duration = microtime(true) - $attemptStart;

                    if ($attempt > $retry) {
                        $this->fire('cronjob.failed', [
                            'job'      => $job,
                            'exception'=> $e,
                            'attempts' => $attempt,
                        ]);
                    } else {
                        $delay = $this->computeBackoffDelay($attempt);
                        if ($delay > 0) {
                            $this->cliWrite("Retrying in {$delay} seconds...", 'yellow');
                            sleep($delay);
                        }
                    }
                } finally {
                    if($job->isEnabled() === true) {
                        $job->clearRunningFlag();
                        $job->endLog();
                        $job->saveLog($result);

                        if($result->isSuccess() && $job->shouldNotifyOnSuccess()) {
                            $job->notify($result);
                        } else {
                            if(! $result->isSuccess() && $job->shouldNotifyOnFailure()) {
                                $job->notify($result);
                            }
                        }
                    }
                }

            } while ($error && $retry <= $maxRetries);

            sleep(config('Jobs')->defaultTimeout ?? 0);
        }
    }

    public function withTestTime(string $time): self
    {
        $this->testTime = Time::createFromInstance(new DateTime($time));

        return $this;
    }

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
    protected function processJob(Job $job): ?Result
    {
        $this->cliWrite('Processing: ' . $job->getName(), 'green');
        $this->markRunningJob($job);

        // Si está destinado a cola, solo encolamos y devolvemos Result informativo
        if ($job->getQueue() !== null) {
            $job->startLog();
            $job->push();
            $result = new Result(true, 'Job enqueued to ' . $job->getQueue());
            $job->endLog();
            $job->saveLog($result);
            $this->cliWrite('Enqueued: ' . $job->getName() . ' to queue ' . $job->getQueue(), 'blue');
            return $result;
        }

        // Ejecución directa mediante JobExecutor
        $executor = new JobExecutor();
        $result   = $executor->execute($job);
        $this->cliWrite('Executed: ' . $job->getName(), 'cyan');
        return $result;
    }

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
            $delay = max(1, $delay + random_int(-$jitterRange, $jitterRange));
        }

        return $delay;
    }

    private function markRunningJob(Job $job): void
    {
        if ($job->isRunning() && $job->isSingleInstance()) {
            throw JobException::TaskAlreadyRunningException($job->getName());
        }

        if ($job->saveRunningFlag()) {
            $this->cliWrite('Marked as running: ' . $job->getName(), 'yellow');
        }
    }

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

    private function shouldSkipTask($job): bool
    {
        return (! empty($this->only) && ! in_array($job->getName(), $this->only, true))
               || (! $job->shouldRun($this->testTime) && empty($this->only));
    }

    /**
     * Fire internal event if enabled. Exceptions from listeners are swallowed (logged at warning level).
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