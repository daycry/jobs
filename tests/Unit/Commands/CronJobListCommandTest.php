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

use CodeIgniter\CLI\Commands;
use Daycry\Jobs\Cronjob\Scheduler;
use Tests\Support\TestCase;

final class _ThrowingCtorLoggerHandler
{
    public function __construct()
    {
        throw new RuntimeException('ctor failed');
    }
}

final class _ThrowingLastRunLoggerHandler
{
    public function lastRun(): string
    {
        throw new RuntimeException('lastRun failed');
    }
}

/**
 * @internal
 */
final class CronJobListCommandTest extends TestCase
{
    public function testListOutputsScheduledJobs(): void
    {
        // Preparar scheduler con trabajos de config
        // Prepare writable logs directory for FileHandler if needed
        $logsPath = __DIR__ . '/../build_tmp_logs';
        if (! is_dir($logsPath)) {
            mkdir($logsPath, 0777, true);
        }
        // Pre-create log file for 'enabled' job to avoid FileHandler warning (empty history)
        file_put_contents($logsPath . '/enabled.json', '[]');
        // Adjust config to disable performance logging & use file logger path we control
        $cfg                 = config('Jobs');
        $cfg->log            = 'file';
        $cfg->logPerformance = false;
        $cfg->filePath       = $logsPath; // override path so handler finds directory
        // Enable via cache flag used by BaseJobsCommand::isActive
        $cache = service('cache');
        $cache->save('jobs_active', (object) ['status' => 'enabled', 'updated_at' => new DateTime()], 0);
        // Use the same scheduler service instance seeded in TestCase::hooksJobs()
        // (avoid creating a fresh empty Scheduler that would make assertions fail)
        $scheduler = service('scheduler');
        // Init config (currently no default jobs registered; keeps seeded ones intact)
        $cfg->init($scheduler);
        $runner = new Commands();
        // Execute command (may write directly to STDOUT bypassing output buffering)
        $runner->run('jobs:cronjob:list', []);
        // Basic assertions: scheduler initialized and has at least one job defined by config init
        $this->assertGreaterThanOrEqual(1, count($scheduler->getJobs()));
        $this->assertNotNull($scheduler->findJobByName('enabled'));
    }

    public function testListWhenDisabledReturnsFailure(): void
    {
        service('cache')->delete('jobs_active');
        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:list', []);
        $this->assertSame(1, $result);
        // Re-enable for other tests
        service('cache')->save('jobs_active', (object) ['status' => 'enabled', 'updated_at' => new DateTime()], 0);
    }

    public function testListWithDatabaseLoggerHandler(): void
    {
        $cfg                 = config('Jobs');
        $cfg->log            = 'database';
        $cfg->logPerformance = true;
        service('cache')->save('jobs_active', (object) ['status' => 'enabled', 'updated_at' => new DateTime()], 0);

        $scheduler = service('scheduler');
        $cfg->init($scheduler);

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:list', []);
        $this->assertSame(0, $result);

        $cfg->log            = 'file';
        $cfg->logPerformance = false;
    }

    public function testListWithNoLoggerClass(): void
    {
        // Handler class doesn't exist - handler will be null
        $cfg          = config('Jobs');
        $cfg->log     = 'no_such_handler';
        $cfg->loggers = ['no_such_handler' => 'NonExistentClass_XYZ999'];
        service('cache')->save('jobs_active', (object) ['status' => 'enabled', 'updated_at' => new DateTime()], 0);

        $scheduler = service('scheduler');
        $cfg->init($scheduler);

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:list', []);
        $this->assertSame(0, $result);

        $cfg->log     = 'file';
        $cfg->loggers = config('Jobs')->loggers; // restore
    }

    public function testListWithThrowingLoggerConstructor(): void
    {
        $cfg          = config('Jobs');
        $original     = $cfg->loggers;
        $cfg->log     = 'throwing_ctor';
        $cfg->loggers = $original + ['throwing_ctor' => _ThrowingCtorLoggerHandler::class];
        service('cache')->save('jobs_active', (object) ['status' => 'enabled', 'updated_at' => new DateTime()], 0);

        $scheduler = service('scheduler');
        $cfg->init($scheduler);

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:list', []);
        $this->assertSame(0, $result);

        $cfg->log     = 'file';
        $cfg->loggers = $original;
    }

    public function testListWithLoggerLastRunThrowing(): void
    {
        $cfg          = config('Jobs');
        $original     = $cfg->loggers;
        $cfg->log     = 'throwing_last_run';
        $cfg->loggers = $original + ['throwing_last_run' => _ThrowingLastRunLoggerHandler::class];
        service('cache')->save('jobs_active', (object) ['status' => 'enabled', 'updated_at' => new DateTime()], 0);

        $scheduler = service('scheduler');
        $cfg->init($scheduler);

        $runner = new Commands();
        $result = $runner->run('jobs:cronjob:list', []);
        $this->assertSame(0, $result);

        $cfg->log     = 'file';
        $cfg->loggers = $original;
    }
}
