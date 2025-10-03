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

use Daycry\Jobs\Execution\JobExecutor;
use Daycry\Jobs\Job;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class LoggingRotationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs                 = config('Jobs');
        $jobs->log            = 'file';
        $jobs->logPerformance = true;
        $jobs->maxLogsPerJob  = 3; // rotación: mantener 3
        $jobs->filePath       = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
        $this->cleanFile('rotation_job');
    }

    private function cleanFile(string $name): void
    {
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/' . $name . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function testLogRotationKeepsOnlyConfiguredEntries(): void
    {
        $executor = new JobExecutor();

        for ($i = 1; $i <= 5; $i++) {
            $job = new Job(job: 'closure', payload: static fn () => 'Run ' . $i);
            $job->named('rotation_job');
            $executor->execute($job);
        }
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/rotation_job.json';
        $this->assertFileExists($file);
        $json = json_decode(file_get_contents($file));
        $this->assertCount(3, $json, 'Should only keep 3 log entries');
        $this->assertStringContainsString('Run 5', $json[0]->output); // más reciente
        $this->assertStringContainsString('Run 3', $json[2]->output); // más antiguo retenido
    }
}
