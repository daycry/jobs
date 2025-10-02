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
        $queues   = Utils::parseConfigFile(config('Jobs')->queues);
        $response = [];

        Services::resetSingle('request');
        Services::resetSingle('response');

        try {
            $worker = $this->getWorker();

            $job = $worker->watch($queue);

            if (isset($job)) {
                $this->locked = true;

                $dataJob = $worker->getDataJob();
                $j       = new Job($dataJob);

                $this->earlyChecks($j);

                $action = $this->config->jobs[$job->getJob()] ?? null;

                if (! $action || ! is_subclass_of($action, Job::class)) {
                    throw JobException::forInvalidJob($job->getJob());
                }
                $action = new $action();

                ob_start();
                $output = $action->handle($job->getPayload());
                $buffer = ob_get_clean(); // Captura y limpia el buffer en una sola llamada

                $buffer = $output->getData() ?? $buffer ?? null;
                $output->setData($buffer);

                //$response = $this->prepareResponse($result);

                $this->lateChecks($j);
            }
        } catch (ExceptionInterface $e) {
            $response = $this->handleException($e, $worker ?? null, $j ?? null);
        }

        if ($response && isset($job)) {
            $this->finalizeJob($response, $worker, $j);
        }

        $this->locked = false;
        unset($j, $job);
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
        try {
            if ($response['status'] === true || $job->getAttempt() >= $job->getMaxRetries()) {
                $worker->removeJob($job, false);
            }

            /*if ($cb = $job->getCallback()) {
                $cb->options->body = $response;
                $c                 = new Job();
                $c->url($cb->url, $cb->options);

                $this->earlyCallbackChecks($c);
                $c->run();
                $this->lateCallbackChecks($c);
            }*/
        } catch (ExceptionInterface $e) {
            $this->showError($e);
        }
    }
}
