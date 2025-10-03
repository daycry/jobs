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

namespace Daycry\Jobs\Jobs;

use Daycry\Jobs\Interfaces\JobInterface;
use Daycry\Jobs\Job;

/**
 * Performs an HTTP request using CodeIgniter curlrequest service.
 * Payload: ['method' => 'GET'|'POST'|..., 'url' => string, 'options' => array(optional)].
 * Returns raw response body string.
 */
class UrlJob extends Job implements JobInterface
{
    public function handle(mixed $payload): mixed
    {
        return service('curlrequest')->request($payload['method'], $payload['url'], $payload['options'] ?? [])->getBody();
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
