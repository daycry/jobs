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

namespace Tests\Unit\Traits;

use CodeIgniter\I18n\Time;
use Config\Services;
use Daycry\Jobs\Job;
use stdClass;
use Tests\Support\TestCase;

/**
 * Extended tests for ActivityTrait — covers shouldRun, lastRun, getLastRunTime.
 *
 * @internal
 */
final class ActivityTraitExtendedTest extends TestCase
{
    private function job(): Job
    {
        return new Job(job: 'command', payload: 'jobs:test');
    }

    public function testShouldRunReturnsTrueForEveryMinute(): void
    {
        $job = $this->job()->everyMinute();
        // Pass in a Time instance to check it is due (every-minute cron is always due)
        $this->assertTrue($job->shouldRun(Time::now()));
    }

    public function testShouldRunReturnsFalseWhenEnvironmentDoesNotMatch(): void
    {
        $job = $this->job()->everyMinute()->environments('production');
        // CI_ENVIRONMENT in tests is typically 'testing' so production restriction should skip
        Services::superglobals()->setServer('CI_ENVIRONMENT', 'testing');
        $this->assertFalse($job->shouldRun(Time::now()));
    }

    public function testShouldRunReturnsTrueWhenEnvironmentMatches(): void
    {
        Services::superglobals()->setServer('CI_ENVIRONMENT', 'production');
        $job = $this->job()->everyMinute()->environments('production');
        $this->assertTrue($job->shouldRun(Time::now()));
        // Reset for other tests
        Services::superglobals()->setServer('CI_ENVIRONMENT', 'testing');
    }

    public function testLastRunReturnsPlaceholderWhenLogDisabled(): void
    {
        $this->jobsConfig()->logPerformance = false;
        $result                             = $this->job()->lastRun();
        $this->assertSame('--', $result);
    }

    public function testLastRunReturnsPlaceholderWhenNoLogger(): void
    {
        $this->jobsConfig()->logPerformance = true;
        $this->jobsConfig()->log            = '';
        $result                             = $this->job()->lastRun();
        $this->assertSame('--', $result);
    }

    public function testGetLastRunTimeReturnsNullForDashResult(): void
    {
        $this->jobsConfig()->logPerformance = false;
        $time                               = $this->job()->getLastRunTime();
        $this->assertNotInstanceOf(Time::class, $time);
    }

    public function testLastRunWithFileHandlerReturnsValue(): void
    {
        $this->jobsConfig()->logPerformance = true;
        $this->jobsConfig()->log            = 'file';
        // Just check it does not throw; the file logger's lastRun() returns '--' or a Time
        $result = $this->job()->named('nonexistent_job')->lastRun();
        $this->assertTrue($result === '--' || $result instanceof Time);
    }

    public function testLastRunWithInvalidLoggerClassReturnsNull(): void
    {
        $cfg                                 = $this->jobsConfig();
        $cfg->logPerformance                 = true;
        $cfg->log                            = 'invalid_test_logger';
        $cfg->loggers['invalid_test_logger'] = 'NonExistentClass_AbcXyz';

        $result = $this->job()->named('test_job_xyz')->lastRun();
        $this->assertSame('--', $result);

        // Restore
        unset($cfg->loggers['invalid_test_logger']);
        $cfg->log = 'file';
    }

    public function testLastRunWithNoLoggerConfigReturnsDefault(): void
    {
        $cfg                 = $this->jobsConfig();
        $cfg->logPerformance = true;
        $cfg->log            = ''; // empty

        $result = $this->job()->named('test_job_empty')->lastRun();
        $this->assertSame('--', $result);

        $cfg->log = 'file';
    }

    public function testLastRunWithHandlerWithoutLastRunMethodReturnsDefault(): void
    {
        // Register a class that exists but doesn't have lastRun()
        $loggerKey                = 'nolastrun';
        $cfg                      = $this->jobsConfig();
        $cfg->logPerformance      = true;
        $cfg->loggers[$loggerKey] = stdClass::class;
        $cfg->log                 = $loggerKey;

        $result = $this->job()->named('test_job_nolastrun')->lastRun();
        $this->assertSame('--', $result);

        $cfg->log = 'file';
        unset($cfg->loggers[$loggerKey]);
    }
}
