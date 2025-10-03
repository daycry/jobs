<?php

declare(strict_types=1);

use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\JobLogger;
use Daycry\Jobs\Execution\ExecutionResult;
use PHPUnit\Framework\TestCase;

final class LoggingExtendedFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure logPerformance enabled
        $cfg = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->maxLogsPerJob  = 5;
        $cfg->filePath       = WRITEPATH . 'jobs/';
        if (! is_dir($cfg->filePath)) {
            mkdir($cfg->filePath, 0777, true);
        }
        // Clean previous file
        $file = $cfg->filePath . 'ext_log.json';
        if (file_exists($file)) {
            unlink($file);
        }
        $cfg->sensitiveKeys = ['password','token'];
    }

    public function testExtendedFieldsAndMasking(): void
    {
        $job = new Job(job: 'command', payload: (object) ['user' => 'alice', 'password' => 'secret', 'details' => ['token' => 'abc123', 'nested' => 'ok']]);
        $job->named('ext_log');
        $job->priority(3);

        $logger = new JobLogger();
        $logger->start();
        usleep(10000); // slight delay
        $result = new ExecutionResult(true, 'OUTPUT DATA', null, microtime(true)-0.02, microtime(true));
        $logger->end();
        $logger->log($job, $result);

        $file = config('Jobs')->filePath . 'ext_log.json';
        $this->assertFileExists($file, 'Log file should exist');
        $decoded = json_decode(file_get_contents($file));
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        $entry = $decoded[0];

        // Check new fields
    $this->assertTrue(property_exists($entry, 'executionId'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $entry->executionId);
        $this->assertSame('ext_log', $entry->name);
    $this->assertTrue(property_exists($entry, 'attempt'));
        $this->assertSame(0, $entry->attempt);
    $this->assertTrue(property_exists($entry, 'outputLength'));
        $this->assertGreaterThan(0, $entry->outputLength);
    $this->assertTrue(property_exists($entry, 'payloadHash'));
        $this->assertNotEmpty($entry->payloadHash);

        // Masking assertions
        $this->assertNotNull($entry->payload);
        $payloadObj = json_decode($entry->payload);
        $this->assertSame('***', $payloadObj->password, 'Password should be masked');
        $this->assertSame('***', $payloadObj->details->token, 'Token should be masked');
        $this->assertSame('ok', $payloadObj->details->nested, 'Non-sensitive field should remain');
    }
}
