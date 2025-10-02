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

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Exceptions\ExceptionInterface;
use CodeIgniter\HTTP\Response;
use Config\Services;
use Daycry\Jobs\Exceptions\JobException;
use Daycry\Jobs\Exceptions\QueueException;
use Daycry\Jobs\Job;
use Daycry\Jobs\Libraries\Utils;
use Daycry\Jobs\Execution\JobExecutor;
use Daycry\Jobs\Result;

class QueueRunCommand extends BaseJobsCommand
{
    protected $name        = 'jobs:queue:run';
    protected $description = 'Start queue worker.';
    protected $usage       = 'queue:run <queue> [Options]';
    protected $arguments   = ['queue' => 'The queue name.'];
    protected $options     = ['--oneTime' => 'Only executes one time.'];
    protected bool $locked = false;

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

        Services::resetSingle('request');
        Services::resetSingle('response');

        try {
            $worker = $this->getWorker();
            $queueEntity = $worker->watch($queue);

            if ($queueEntity !== null) {
                $this->locked = true;

                $decoded = json_decode($queueEntity->payload); // payload completo que incluye datos del Job original
                if (! is_object($decoded)) {
                    throw JobException::validationError('Invalid queued payload format.');
                }

                $job = Job::fromQueueRecord($decoded);

                $this->earlyChecks($job);

                $executor = new JobExecutor();
                $result   = $executor->execute($job);

                $this->lateChecks($job);

                $response = [
                    'status'     => $result->isSuccess(),
                    'statusCode' => $result->isSuccess() ? 200 : 500,
                    'data'       => $result->getData(),
                    'error'      => $result->isSuccess() ? null : $result->getData(),
                ];

                // Finalizar/eliminar o recrear según éxito
                if ($result->isSuccess()) {
                    $worker->removeJob($job, false);
                } else {
                    $worker->removeJob($job, true); // reintenta (recrea)
                }
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

    protected function prepareResponse($result): array
    {
        $response['status'] = true;

        if (! $result instanceof Response) {
            $result = (Services::response(null, true))->setStatusCode(200)->setBody($result);
        }

        $response['statusCode'] = $result->getStatusCode();
        $response['data']       = $result->getBody();

        return $response;
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

    protected function finalizeJob(array $response, $worker, Job $job): void
    {
        // Ya no se usa: la lógica de finalize se hace inline tras ejecutar el JobExecutor.
    }
}
