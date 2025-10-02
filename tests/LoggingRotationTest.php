<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;
use Daycry\Jobs\Execution\JobExecutor;

final class LoggingRotationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs = config('Jobs');
        $jobs->log = 'file';
        $jobs->logPerformance = true;
        $jobs->maxLogsPerJob = 3; // rotación: mantener 3
        $this->cleanFile('rotation_job');
    }

    private function cleanFile(string $name): void
    {
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/' . $name . '.json';
        if (file_exists($file)) { unlink($file); }
    }

    public function testLogRotationKeepsOnlyConfiguredEntries(): void
    {
        $executor = new JobExecutor();
        for ($i = 1; $i <= 5; $i++) {
            $job = new Job(job: 'closure', payload: fn() => 'Run ' . $i);
            $job->named('rotation_job');
            $executor->execute($job);
        }
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/rotation_job.json';
        $this->assertFileExists($file);
        $json = json_decode(file_get_contents($file));
        $this->assertCount(3, $json, 'Should only keep 3 log entries');
        // La entrada más reciente debe ser la última ejecución (Run 5)
        $this->assertStringContainsString('Run 5', $json[0]->output);
        // La más antigua persistente debe ser Run 3 (porque Run1 y Run2 fueron expulsadas)
        $this->assertStringContainsString('Run 3', $json[2]->output);
    }
}
