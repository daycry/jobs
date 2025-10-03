<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\JobLogger;
use Daycry\Jobs\Execution\ExecutionResult;
use PHPUnit\Framework\TestCase;

final class LoggingCustomSensitiveKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->maxLogsPerJob  = 5;
        $cfg->filePath       = WRITEPATH . 'jobs/';
        if (! is_dir($cfg->filePath)) {
            mkdir($cfg->filePath, 0777, true);
        }
        $file = $cfg->filePath . 'custom_sensitive.json';
        if (file_exists($file)) {
            unlink($file);
        }
        // Extend sensitive keys list dynamically (api_key exists by default but we ensure custom addition works)
        $cfg->sensitiveKeys[] = 'custom_token';
    }

    public function testCustomSensitiveKeysAreMasked(): void
    {
        $payload = [
            'password'    => 'p@ss', // default masked
            'api_key'     => 'API-123', // default masked
            'custom_token'=> 'XYZ999', // custom masked
            'public'      => 'ok',
            'nested'      => ['custom_token' => 'INNER', 'public' => 'still'],
        ];
        $job = new Job(job: 'command', payload: $payload);
        $job->named('custom_sensitive');

        $logger = new JobLogger();
        $logger->start();
        $result = new ExecutionResult(true, 'DONE', null, microtime(true)-0.02, microtime(true));
        $logger->end();
        $logger->log($job, $result);

        $file = config('Jobs')->filePath . 'custom_sensitive.json';
        $this->assertFileExists($file);
        $entries = json_decode(file_get_contents($file));
        $this->assertIsArray($entries);
        $entry = $entries[0];
        $this->assertNotNull($entry->payload);
        $decoded = json_decode($entry->payload, true);

        $this->assertSame('***', $decoded['password']);
        $this->assertSame('***', $decoded['api_key']);
        $this->assertSame('***', $decoded['custom_token']);
        $this->assertSame('ok', $decoded['public']);
        $this->assertSame('***', $decoded['nested']['custom_token']);
        $this->assertSame('still', $decoded['nested']['public']);
    }
}
