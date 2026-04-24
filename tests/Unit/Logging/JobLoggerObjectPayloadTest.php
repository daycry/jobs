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

use Daycry\Jobs\Execution\ExecutionResult;
use Daycry\Jobs\Job;
use Daycry\Jobs\Loggers\JobLogger;
use Tests\Support\TestCase;

/**
 * @internal
 */
final class JobLoggerObjectPayloadTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/jobs_jltest_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->tmpDir, 0777, true);

        $cfg                  = config('Jobs');
        $cfg->logPerformance  = true;
        $cfg->log             = 'file';
        $cfg->filePath        = $this->tmpDir;
        $cfg->maxOutputLength = null;
        $cfg->sensitiveKeys   = ['secret'];
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '*');

        foreach ($files !== false ? $files : [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testLogWithObjectPayloadMasksSensitiveKey(): void
    {
        $payload = (object) ['username' => 'alice', 'secret' => 'top_secret'];
        $job     = new Job(job: 'command', payload: $payload);
        $job->named('test_obj_mask');

        $logger = new JobLogger();
        $logger->start();
        $result = new ExecutionResult(true, 'done', null, microtime(true) - 0.01, microtime(true));
        $logger->end();
        $logger->log($job, $result);

        $file = $this->tmpDir . 'test_obj_mask.json';
        $this->assertFileExists($file);
        $data  = json_decode(file_get_contents($file));
        $entry = $data[0];
        $this->assertIsObject($entry);
        // Payload should have sensitive key masked
        $payloadDecoded = json_decode($entry->payload);
        $this->assertSame('***', $payloadDecoded->secret ?? null);
    }

    public function testLogWithObjectOutputMasksTokenPattern(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $job->named('test_token_mask');

        $logger = new JobLogger();
        $logger->start();
        // Use a JWT-like string (three parts with dots) to test JWT masking
        $jwtLike = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $result  = new ExecutionResult(true, "Token: {$jwtLike}", null, microtime(true) - 0.01, microtime(true));
        $logger->end();
        $logger->log($job, $result);

        $file = $this->tmpDir . 'test_token_mask.json';
        $this->assertFileExists($file);
        $data  = json_decode(file_get_contents($file));
        $entry = $data[0];
        $this->assertStringContainsString('***', (string) ($entry->output ?? ''));
    }

    public function testLogWithArrayOutputSanitizes(): void
    {
        $job = new Job(job: 'command', payload: ['key' => 'value', 'secret' => 'hidden']);
        $job->named('test_arr_out');

        $logger = new JobLogger();
        $logger->start();
        $result = new ExecutionResult(true, json_encode(['apikey' => 'abc']), null, microtime(true) - 0.01, microtime(true));
        $logger->end();
        $logger->log($job, $result);

        $file = $this->tmpDir . 'test_arr_out.json';
        $this->assertFileExists($file);
        $this->assertNotSame('', (string) file_get_contents($file));
    }

    public function testLogWithNullStartAndEnd(): void
    {
        $job = new Job(job: 'command', payload: 'test');
        $job->named('test_null_times');

        $logger = new JobLogger();
        // Don't call start/end; they will be inferred from result
        $result = new ExecutionResult(true, 'done', null, microtime(true) - 0.1, microtime(true));
        $logger->log($job, $result);

        $file = $this->tmpDir . 'test_null_times.json';
        $this->assertFileExists($file);
        $this->assertNotSame('', (string) file_get_contents($file));
    }
}
