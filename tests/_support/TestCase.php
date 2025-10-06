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
        // Create a scheduled job instance for tests (do not enqueue to queues; we only need it in the scheduler list)
        $job = (new Job(job: 'command', payload: 'jobs:test'))
            ->everyMinute()
            ->named('enabled')
            ->singleInstance()
            ->maxRetries(3)
            ->timeout(30)
            ->environments('testing')
            ->priority(5);
        $scheduler = service('scheduler');
        $this->setPrivateProperty($scheduler, 'jobs', [$job]);
        Services::injectMock('scheduler', $scheduler);
    }
}
