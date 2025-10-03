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
use Daycry\Jobs\Cronjob\JobRunner;

/**
 * Core scheduler runner: evaluates cron expressions and executes due jobs.
 * Intended to be executed every minute by system cron.
 */
class CronJobRunCommand extends BaseJobsCommand
{
    protected $name        = 'jobs:cronjob:run';
    protected $description = 'Runs jobs based on the schedule, should be configured as a crontask to run every minute.';
    protected $usage       = 'jobs:cronjob:run [options]';
    protected $options     = [
        '-testTime' => 'Set Date to run script',
        '-only'     => 'Set name of jobs that you want run separated with comma',
        '-oneTime'  => 'Run only one time and exit',
    ];

    public function run(array $params)
    {
        if ($this->isActive() === false) {
            $this->tryToEnable();

            return false;
        }

        $this->getConfig();

        CLI::newLine(1);
        CLI::write('**** Running Tasks... ****', 'white', 'red');
        CLI::newLine(1);

        $this->config->init(service('scheduler'));

        $runner = new JobRunner();

        $testTime = $params['testTime'] ?? CLI::getOption('testTime');

        if ($testTime) {
            $runner->withTestTime($testTime);
        }

        $only = $params['only'] ?? CLI::getOption('only');

        if ($only) {
            $only = explode(',', $only);
            $runner->only($only);
        }

        $runner->run();
    }
}
