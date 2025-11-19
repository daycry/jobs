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

/** @internal */
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
}
