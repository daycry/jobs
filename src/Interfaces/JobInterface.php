<?php

declare(strict_types=1);

namespace Daycry\Jobs\Interfaces;

use CodeIgniter\HTTP\ResponseInterface;
use Daycry\Jobs\Job;
use Daycry\Jobs\Result;

interface JobInterface
{
    public function handle(mixed $payload): Result;
    public function beforeRun(Job $job): Job;
    public function afterRun(Job $job): Job;
}
