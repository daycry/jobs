<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\JobLogger;
use Daycry\Jobs\Execution\ExecutionResult;
use PHPUnit\Framework\TestCase;

final class LoggingErrorMaskingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->filePath       = WRITEPATH . 'jobs/';
        if (! is_dir($cfg->filePath)) {
            mkdir($cfg->filePath, 0777, true);
        }
        $file = $cfg->filePath . 'error_mask.json';
        if (file_exists($file)) {
            unlink($file);
        }
        $cfg->sensitiveKeys = ['password','secret'];
    }

    public function testErrorOutputMasked(): void
    {
        $payload = ['user' => 'bob', 'password' => 'mypw'];
        $job = new Job(job: 'command', payload: $payload);
        $job->named('error_mask');
        $job->source('queue');

        $logger = new JobLogger();
        $logger->start();
        $result = new ExecutionResult(false, null, 'Failure with secret=TOPSECRET', microtime(true)-0.05, microtime(true));
        $logger->end();
        $logger->log($job, $result);

        $file = config('Jobs')->filePath . 'error_mask.json';
        $this->assertFileExists($file);
        $data = json_decode(file_get_contents($file));
        $this->assertNotEmpty($data);
        $entry = $data[0];
        $this->assertSame('queue', $entry->source);
        $this->assertNull($entry->output);
        $this->assertNotNull($entry->error);
        // Verify payload masking
        $payloadObj = json_decode($entry->payload, true);
        $this->assertSame('***', $payloadObj['password']);
    }
}
