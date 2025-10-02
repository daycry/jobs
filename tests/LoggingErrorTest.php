<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;
use Daycry\Jobs\Execution\JobExecutor;

final class LoggingErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $jobs = config('Jobs');
        $jobs->log = 'file';
        $jobs->logPerformance = true;
        $this->cleanFile('error_job');
    }

    private function cleanFile(string $name): void
    {
        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/' . $name . '.json';
        if (file_exists($file)) { unlink($file); }
    }

    public function testErrorIsLogged(): void
    {
        $job = new Job(job: 'closure', payload: function () { throw new RuntimeException('Boom'); });
        $job->named('error_job');
        (new JobExecutor())->execute($job);

        $file = rtrim(config('Jobs')->filePath, DIRECTORY_SEPARATOR) . '/error_job.json';
        $this->assertFileExists($file);
        $json = json_decode(file_get_contents($file));
        $first = $json[0];
        $this->assertSame('error_job', $first->name);
        $this->assertNull($first->output, 'Output should be null when error');
        $this->assertNotNull($first->error, 'Error should be captured');
        $this->assertStringContainsString('Boom', $first->error);
    }
}
