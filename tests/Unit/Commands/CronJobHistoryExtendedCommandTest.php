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
use Daycry\Jobs\Libraries\ConfigCache;
use Daycry\Jobs\Loggers\FileHandler;
use Daycry\Jobs\Loggers\JobLogger;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CronJobHistoryExtendedCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg                 = config('Jobs');
        $cfg->logPerformance = true;
        $cfg->filePath       = WRITEPATH . 'jobs/';
        if (! is_dir($cfg->filePath)) {
            mkdir($cfg->filePath, 0777, true);
        }
        // Ensure FileHandler uses the updated config
        ConfigCache::set($cfg);
        $job = new Job(job: 'command', payload: ['demo' => 'x']);
        $job->named('hist_demo');
        $job->source('cron');
        $logger = new JobLogger();
        $logger->start();
        $result = new ExecutionResult(true, 'OK', null, microtime(true) - 0.01, microtime(true));
        $logger->end();
        $logger->log($job, $result);
    }

    public function testExtendedFieldsAvailableInHistoryHandler(): void
    {
        $handler = new FileHandler();
        $history = $handler->history('hist_demo', 1);
        $this->assertNotEmpty($history);
        $row = $history[0];
        $this->assertTrue(property_exists($row, 'attempt'));
        $this->assertTrue(property_exists($row, 'source'));
        $this->assertTrue(property_exists($row, 'outputLength'));
        $this->assertTrue(property_exists($row, 'payloadHash'));
    }

    protected function tearDown(): void
    {
        ConfigCache::clear();
        parent::tearDown();
    }
}
