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

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Daycry\Jobs\Cron\CronRunner;
use Daycry\Jobs\Cron\Scheduler;

/**
 * v3 cron runner command: evaluates the scheduled definitions and runs (or enqueues) the due
 * ones. Intended to be wired to system cron every minute.
 *
 * Replaces the v1 'jobs:cronjob:run' (which drove the legacy {@see \Daycry\Jobs\Cronjob\JobRunner})
 * but keeps the same command name so existing crontab entries keep working.
 */
final class CronRunCommand extends BaseJobsCommand
{
    protected $name        = 'jobs:cronjob:run';
    protected $description = 'Runs jobs based on the schedule; configure it as a crontask to run every minute.';
    protected $usage       = 'jobs:cronjob:run [options]';
    protected $options     = [
        '-testTime' => 'Frozen ISO datetime used to evaluate the schedule (testing/dry-run).',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $this->getConfig();

        $scheduler = new Scheduler();
        $this->config->init($scheduler);

        $testTimeArg = $params['testTime'] ?? CLI::getOption('testTime');
        $testTime    = is_string($testTimeArg) && $testTimeArg !== '' ? Time::parse($testTimeArg) : null;

        (new CronRunner($scheduler, $this->config))->run($testTime);

        return self::SUCCESS;
    }
}
