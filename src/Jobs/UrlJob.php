<?php

declare(strict_types=1);

namespace Daycry\Jobs\Jobs;

use CodeIgniter\HTTP\ResponseInterface;
use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Result;

class UrlJob extends Job implements JobInterface
{
    public function handle(mixed $payload): Result
    {
        $response = service('curlrequest')->request($payload['method'], $payload['url'], $payload['options'] ?? [])->getBody();
        $result = new Result();
        $result->setData($response);
        $result->setSuccess(true);

        return $result;
    }

    public function beforeRun(Job $job): Job
    {
        return $job;
    }

    public function afterRun(Job $job): Job
    {
        return $job;
    }
}