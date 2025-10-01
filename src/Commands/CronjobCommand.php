<?php

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\CLI;
use Daycry\CronJob\Config\CronJob;
use Daycry\CronJob\Config\Services;
use Daycry\Jobs\Cronjob\JobRunner;

/**
 * Runs current tasks.
 */
class CronjobCommand extends BaseJobsCommand
{
    protected $name = 'cronjob:run';

    protected $description = 'Runs tasks based on the schedule, should be configured as a crontask to run every minute.';

    protected $usage = 'cronjob:run [options]';

    protected $options = [
        '-testTime' => 'Set Date to run script',
        '-only' => 'Set name of jobs that you want run separated with comma',
        '-oneTime' => 'Run only one time and exit'
    ];

    public function run(array $params)
    {
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