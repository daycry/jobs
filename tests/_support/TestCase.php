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
        return config(Jobs::class) ?? new Jobs();
    }

    /**
     * Read a FileHandler log file as a list of stdClass entries, newest first.
     *
     * Supports both layouts the FileHandler can produce:
     *  - Legacy JSON-array (pre-v1.1): `[{...},{...}]` newest-first.
     *  - NDJSON (v1.1+): one JSON object per line, oldest-first on disk.
     *
     * @return array<int, object>
     */
    protected function readJobLogFile(string $file): array
    {
        if (! file_exists($file)) {
            return [];
        }

        $fp = fopen($file, 'rb');
        if ($fp === false) {
            return [];
        }

        $first = '';

        while (! feof($fp) && ($c = fgetc($fp)) !== false) {
            if (! ctype_space($c)) {
                $first = $c;
                break;
            }
        }
        rewind($fp);

        if ($first === '[') {
            $raw = stream_get_contents($fp);
            fclose($fp);
            $decoded = is_string($raw) ? json_decode($raw) : null;

            return is_array($decoded) ? $decoded : [];
        }

        $entries = [];

        while (($line = fgets($fp)) !== false) {
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '') {
                continue;
            }
            $obj = json_decode($trimmed);
            if ($obj !== null) {
                $entries[] = $obj;
            }
        }
        fclose($fp);

        // NDJSON is oldest-first on disk; tests expect newest-first ordering.
        return array_reverse($entries);
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

        $job = (new Job(job: 'closure', payload: // Your closure code here
            static fn () => 'Closure executed successfully!'))
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
