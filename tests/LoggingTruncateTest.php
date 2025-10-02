<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;
use Daycry\Jobs\Execution\JobExecutor;

final class LoggingTruncateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs = config('Jobs');
        $jobs->log = 'file';
        $jobs->logPerformance = true;
        $jobs->maxOutputLength = 50; // for test
        $this->cleanFile('truncate_job');
    }

    private function cleanFile(string $name): void
    {
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/' . $name . '.json';
        if (file_exists($file)) { unlink($file); }
    }

    public function testOutputIsTruncated(): void
    {
        $payload = str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 5); // 130 chars
        $job = new Job(job: 'closure', payload: fn() => $payload);
        $job->named('truncate_job');
        (new JobExecutor())->execute($job);

        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/truncate_job.json';
        $this->assertFileExists($file);
        $json = json_decode(file_get_contents($file));
        $first = $json[0];
        $this->assertLessThan(strlen($payload), strlen($first->output));
        $this->assertStringContainsString('[truncated', $first->output);
    }
}
