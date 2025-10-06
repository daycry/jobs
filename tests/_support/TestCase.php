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

namespace Tests\Support;

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Job;

/**
 * @internal
 */
abstract class TestCase extends CIUnitTestCase
{
    protected function setUp(): void
    {
        $this->resetServices();

        parent::setUp();

        // Asegurar configuración Jobs mínima para tests unitarios
        $jobs                 = $this->jobsConfig();
        $jobs->logPerformance = false;
        $jobs->log            = 'file';
        $jobs->filePath       = WRITEPATH . 'logs';
        if (! is_dir($jobs->filePath)) {
            @mkdir($jobs->filePath, 0777, true);
        }

        $this->hooksJobs();
    }

    protected function injectMockQueueWorker(string $worker): void
    {
        $this->jobsConfig()->worker = $worker;
    }

    /**
     * Helper accessor returning typed Jobs config for static analysis.
     */
    protected function jobsConfig(): Jobs
    {
        /** @var Jobs $cfg */
        return config('Jobs');
    }

    protected function hooksJobs(): void
    {
        $jobs = [];
        // Create a scheduled job instance for tests (do not enqueue to queues; we only need it in the scheduler list)
        $job = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('enabled')
            ->singleInstance()
            ->maxRetries(3)
            ->timeout(30)
            ->environments('testing')
            ->priority(5);
        $jobs[] = $job;

        $job = (new Job(job: 'closure', payload: static function () {
            // Your closure code here
            return 'Closure executed successfully!';
        }))
            ->everyMinute()
            ->named('closure_enabled')
            ->singleInstance()
            ->maxRetries(3)
            ->timeout(30)
            ->environments('testing')
            ->priority(5);
        $jobs[] = $job;

        $job = (new Job(job: 'url', payload: [
            'url'     => 'https://google.es',
            'method'  => 'GET',
            'options' => ['headers' => ['Accept' => 'application/html']],
        ]))
            ->everyMinute()
            ->named('url_enabled')
            ->singleInstance()
            ->maxRetries(3)
            ->timeout(30)
            ->environments('testing')
            ->priority(5);
        $jobs[] = $job;

        $scheduler = service('scheduler');
        $this->setPrivateProperty($scheduler, 'jobs', $jobs);
        Services::injectMock('scheduler', $scheduler);
    }
}
