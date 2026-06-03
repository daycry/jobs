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

namespace Tests\Unit\V3\Coverage;

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use Daycry\Jobs\Config\Jobs;
use Daycry\Jobs\Cron\CronRunner;
use Daycry\Jobs\Cron\Scheduler;

/**
 * Covers CronRunner branches around the config-resolved backend enqueue path (sync backend),
 * the dueDefinitions()/scheduler() accessors, and the combination of disabled + environment-gated
 * + queued jobs evaluated in dependency order.
 *
 * @internal
 */
final class CovCronRunnerTest extends CIUnitTestCase
{
    private function syncConfig(): Jobs
    {
        $config         = config(Jobs::class);
        $config->worker = 'sync';

        return $config;
    }

    public function testQueuedJobIsEnqueuedThroughConfigResolvedSyncBackend(): void
    {
        // The sync backend executes inline at enqueue() time, so a queued job here actually runs
        // through BackendFactory::make(config)->enqueue() — covering the queue branch of run().
        $ran       = false;
        $scheduler = new Scheduler();
        $scheduler->define('closure', static function () use (&$ran): string {
            $ran = true;

            return 'ok';
        })->named('cov-queued')->everyMinute()->queue('cov-reports');

        $runner = new CronRunner($scheduler, $this->syncConfig());
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertTrue($ran, 'Queued job must reach the sync backend enqueue (which runs inline).');
    }

    public function testInlineJobRunsAndQueuedJobEnqueuesInDependencyOrder(): void
    {
        $order     = [];
        $scheduler = new Scheduler();

        // 'first' is inline; 'second' depends on 'first' and is queued (sync => runs inline).
        $scheduler->define('closure', static function () use (&$order): void {
            $order[] = 'first';
        })->named('first')->everyMinute();

        $scheduler->define('closure', static function () use (&$order): void {
            $order[] = 'second';
        })->named('second')->everyMinute()->queue('cov-q')->dependsOn('first');

        $runner = new CronRunner($scheduler, $this->syncConfig());
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertSame(['first', 'second'], $order);
    }

    public function testDisabledAndForeignEnvironmentJobsAreSkippedWhileDueInlineRuns(): void
    {
        $ran       = ['on' => false, 'off' => false, 'prod' => false];
        $scheduler = new Scheduler();

        $scheduler->define('closure', static function () use (&$ran): void {
            $ran['on'] = true;
        })->named('cov-on')->everyMinute();

        $scheduler->define('closure', static function () use (&$ran): void {
            $ran['off'] = true;
        })->named('cov-off')->everyMinute()->disable();

        // ENVIRONMENT is 'testing' during the suite => a 'production'-only job is skipped.
        $scheduler->define('closure', static function () use (&$ran): void {
            $ran['prod'] = true;
        })->named('cov-prod')->everyMinute()->environments('production');

        $runner = new CronRunner($scheduler, $this->syncConfig());
        $runner->run(Time::parse('2026-06-01 10:00:00'));

        $this->assertTrue($ran['on']);
        $this->assertFalse($ran['off'], 'Disabled job must be skipped.');
        $this->assertFalse($ran['prod'], 'Foreign-environment job must be skipped.');
    }

    public function testDueDefinitionsFiltersByEnabledAndSchedule(): void
    {
        $scheduler = new Scheduler();
        $scheduler->define('closure', static fn (): string => 'a')->named('cov-due')->everyMinute();
        $scheduler->define('closure', static fn (): string => 'b')->named('cov-nightly')->dailyAt('02:00');
        $scheduler->define('closure', static fn (): string => 'c')->named('cov-disabled')->everyMinute()->disable();

        $runner = new CronRunner($scheduler, $this->syncConfig());
        $due    = $runner->dueDefinitions(Time::parse('2026-06-01 10:00:00'));

        // Only the enabled, every-minute job is due at 10:00 (nightly not due; disabled excluded).
        $this->assertCount(1, $due);
        $this->assertSame('cov-due', $due[0]->name);

        // scheduler() returns the same instance we registered on.
        $this->assertSame($scheduler, $runner->scheduler());
    }
}
