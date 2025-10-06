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
use CodeIgniter\HTTP\Response;
use Config\Services;
use DateTimeInterface;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Exceptions\QueueException;
use Daycry\Jobs\Execution\ExecutionContext;
use Daycry\Jobs\Execution\JobLifecycleCoordinator;
use Daycry\Jobs\Job;
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
    protected $options                    = ['--oneTime' => 'Only executes one time.'];
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

    public function run(array $params): void
    {
        $queue   = $params[0] ?? CLI::getOption('queue');
        $oneTime = array_key_exists('oneTime', $params) || CLI::getOption('oneTime');

        if (empty($queue)) {
            $queue = CLI::prompt(lang('Queue.insertQueue'));
        }

        while (true) {
            $this->processQueue($queue);

            if ($oneTime) {
                return;
            }

            sleep(config('Jobs')->defaultTimeout ?? 5);
        }
    }

    protected function processQueue(string $queue): void
    {
        $response = [];
        $metrics  = Metrics::get();
        $this->requeueHelper ??= new RequeueHelper($metrics);

        Services::resetSingle('request');
        Services::resetSingle('response');

        try {
            $worker      = $this->getWorker();
            $queueEntity = $worker->watch($queue);

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

                $ctx = new ExecutionContext(
                    source: 'queue',
                    maxRetries: $job->getMaxRetries() ?? 0,
                    notifyOnSuccess: $job->shouldNotifyOnSuccess(),
                    notifyOnFailure: $job->shouldNotifyOnFailure(),
                    singleInstance: $job->isSingleInstance(),
                    queueName: $queue,
                    queueWorker: $worker,
                    retryConfig: [
                        'strategy'   => config('Jobs')->retryBackoffStrategy,
                        'base'       => config('Jobs')->retryBackoffBase,
                        'multiplier' => config('Jobs')->retryBackoffMultiplier,
                        'jitter'     => config('Jobs')->retryBackoffJitter,
                        'max'        => config('Jobs')->retryBackoffMax,
                    ],
                    eventsEnabled: config('Jobs')->enableEvents ?? true,
                    meta: [],
                );

                $coordinator = new JobLifecycleCoordinator();
                $startExec   = microtime(true);
                $outcome     = $coordinator->run($job, $ctx);
                $latency     = microtime(true) - $startExec;
                $exec        = $outcome->finalResult;

                $response = [
                    'status'     => $exec->success,
                    'statusCode' => $exec->success ? 200 : 500,
                    'data'       => $exec->output,
                    'error'      => $exec->success ? null : $exec->error,
                ];

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
        $workers = config('Jobs')->workers;
        $worker  = config('Jobs')->worker;

        if (! array_key_exists($worker, $workers)) {
            throw QueueException::forInvalidWorker($worker);
        }

        return new $workers[$worker]();
    }

    /*protected function prepareResponse($result): array
    {
        $response['status'] = true;

        if (! $result instanceof Response) {
            $result = (Services::response(null, true))->setStatusCode(200)->setBody($result);
        }

        $response['statusCode'] = $result->getStatusCode();
        $response['data']       = $result->getBody();

        return $response;
    }*/

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

    protected function finalizeJob(array $response, $worker, Job $job): void
    {
        // Ya no se usa: la lógica de finalize se hace inline tras ejecutar el JobExecutor.
    }

    // Metrics retrieval now centralized in Metrics::get(); no local logic needed.
}
