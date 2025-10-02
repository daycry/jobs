<?php
declare(strict_types=1);

use Tests\Support\TestCase;
use Daycry\Jobs\Job;
use Daycry\Jobs\Execution\JobExecutor;

final class LoggingFileTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $jobs = config('Jobs');
        $jobs->log = 'file';
        $jobs->logPerformance = true; // habilitar logging
        $this->logDir = rtrim($jobs->filePath, DIRECTORY_SEPARATOR);
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
        // Limpiar archivos previos del job de prueba
        $target = $this->logDir . '/log_closure.json';
        if (file_exists($target)) { unlink($target); }
    }

    public function testFileLogIsCreatedAndContainsExpectedKeys(): void
    {
        $job = new Job(job: 'closure', payload: fn() => 'OUT');
        $job->named('log_closure');
        $executor = new JobExecutor();
        $result = $executor->execute($job);
        $this->assertTrue($result->isSuccess());

        $file = $this->logDir . '/log_closure.json';
        $this->assertFileExists($file, 'Log file not created');
        $json = json_decode(file_get_contents($file));
        $this->assertIsArray($json);
        $this->assertNotEmpty($json);
        $first = $json[0];
        $this->assertSame('log_closure', $first->name);
        $this->assertSame('closure', $first->job);
        $this->assertObjectHasProperty('start_at', $first);
        $this->assertObjectHasProperty('end_at', $first);
        $this->assertObjectHasProperty('duration', $first);
        $this->assertSame('OUT', $first->output);
        $this->assertNull($first->error);
    }
}
