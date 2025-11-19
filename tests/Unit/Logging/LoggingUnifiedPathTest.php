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
use Daycry\Jobs\Loggers\FileHandler;
use Daycry\Jobs\Loggers\JobLogger;
use PHPUnit\Framework\TestCase;

/** @internal */
final class LoggingUnifiedPathTest extends TestCase
{
    public function testQueueAndCronShareJobNameFile(): void
    {
        $cfg                 = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->log            = 'file';
        $cfg->filePath       = sys_get_temp_dir() . '/jobs_unified_' . bin2hex(random_bytes(3));
        if (! is_dir($cfg->filePath)) {
            mkdir($cfg->filePath, 0777, true);
        }

        $job = new Job(job: 'command', payload: 'jobs:test');
        $job->named('unified_name');

        $logger = new JobLogger();
        $logger->start();
        $result = new ExecutionResult(success: true, output: 'ok', error: null, startedAt: microtime(true), endedAt: microtime(true));
        $logger->end();
        $logger->log($job, $result);

        $file = $cfg->filePath . '/unified_name.json';
        $this->assertFileExists($file);
        $contents = json_decode(file_get_contents($file));
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);
        $this->assertSame('unified_name', $contents[0]->name);

        // lastRun should return '--' for non-existent
        $handler = new FileHandler();
        $this->assertSame('--', $handler->lastRun('nonexistent_job'));
    }
}
