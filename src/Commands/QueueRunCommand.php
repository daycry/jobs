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
use CodeIgniter\Exceptions\ExceptionInterface;
use Config\Services;
use DateTimeInterface;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\QueueManager;
use Daycry\Jobs\Libraries\RateLimiter;
use Daycry\Jobs\Metrics\Metrics;
use Daycry\Jobs\Queues\JobEnvelope;
use Daycry\Jobs\Queues\RequeueHelper;

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

            if (str_starts_with(strtolower(PHP_OS), strtolower('WIN'))) {
                // Windows: use start /B and redirect to NUL
                $cmd    = sprintf('%s %s %s', escapeshellarg($phpBin), escapeshellarg($sparkPath), $this->name . ' --queue ' . escapeshellarg($queue) . ($oneTime ? ' --oneTime' : ''));
                $winCmd = 'start "" /B ' . $cmd . ' > NUL 2>&1';
                pclose(popen($winCmd, 'r'));
            } else {
                // POSIX: use nohup and redirect to /dev/null, detach with &
                $cmd      = sprintf('%s %s %s', escapeshellarg($phpBin), escapeshellarg($sparkPath), $this->name . ' --queue ' . escapeshellarg($queue) . ($oneTime ? ' --oneTime' : ''));
                $posixCmd = 'nohup ' . $cmd . ' > /dev/null 2>&1 &';
                exec($posixCmd);
            }

            return;
        }

        if (empty($queue)) {
            $queue = CLI::prompt(lang('Queue.insertQueue'), config('Jobs')->queues, 'required');
        }

        while (true) {
            if ($this->conditionalChecks()) {
                $this->processQueue($queue);

                if ($oneTime) {
                    return;
                }

                sleep(config('Jobs')->defaultTimeout ?? 5);
            }
        }
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

        Services::resetSingle('request');
        Services::resetSingle('response');

        try {
            $worker      = $this->getWorker();
            $queueEntity = $worker->watch($queue);

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
                if ($queueEntity instanceof JobEnvelope && $queueEntity->createdAt instanceof DateTimeInterface) {
                    $age = microtime(true) - $queueEntity->createdAt->getTimestamp();
                    $metrics->observe('jobs_age_seconds', $age, ['queue' => $queue]);
                }
                $metrics->observe('jobs_exec_seconds', $latency, ['queue' => $queue]);
            }
        } catch (ExceptionInterface $e) {
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

        return env('PHP_BINARY_PATH', 'php');
    }
}
