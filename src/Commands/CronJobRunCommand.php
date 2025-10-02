<?php

declare(strict_types=1);

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\CLI;
use Daycry\CronJob\Config\CronJob;
use Daycry\CronJob\Config\Services;
use Daycry\Jobs\Cronjob\JobRunner;

/**
 * Runs current jobs.
 */
class CronJobRunCommand extends BaseJobsCommand
{
    protected $name = 'jobs:cronjob:run';

    protected $description = 'Runs jobs based on the schedule, should be configured as a crontask to run every minute.';

    protected $usage = 'jobs:cronjob:run [options]';

    protected $options = [
        '-testTime' => 'Set Date to run script',
        '-only' => 'Set name of jobs that you want run separated with comma',
        '-oneTime' => 'Run only one time and exit'
    ];

    public function run(array $params)
    {
        if($this->isActive() === false) {
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