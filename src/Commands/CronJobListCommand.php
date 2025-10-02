<?php

declare(strict_types=1);

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Cron\CronExpression;
use Daycry\CronJob\Config\CronJob;
use Daycry\CronJob\Config\Services;

/**
 * Lists currently scheduled tasks.
 */
class CronJobListCommand extends BaseJobsCommand
{
    /**
     * The Command's name
     *
     * @var string
     */
    protected $name = 'jobs:cronjob:list';

    /**
     * the Command's short description
     *
     * @var string
     */
    protected $description = 'Lists the cronjobs currently set to run.';

    /**
     * the Command's usage
     *
     * @var string
     */
    protected $usage = 'jobs:cronjob:list';

    /**
     * Lists upcoming tasks
     */
    public function run(array $params)
    {
        $this->getConfig();
        if(! $this->isActive()) {
            $this->tryToEnable();
            return false;
        }

        $scheduler = service('scheduler');
        config('Jobs')->init($scheduler);

        $jobs = [];

        foreach ($scheduler->getJobs() as $job) {
            $cron    = new CronExpression($job->getExpression());
            $nextRun = ($job->isEnabled()) ? $cron->getNextRunDate()->format('Y-m-d H:i:s') : '--';
            $lastRun = $job->lastRun();

            $jobs[] = [
                'name'     => $job->getName() ?: $job->getAction(),
                'job'     => $job->getJob(),
                'schedule' => $job->getExpression(),
                'last_run' => $lastRun instanceof Time ? $lastRun->format('Y-m-d H:i:s') : $lastRun,
                'next_run' => $nextRun,
            ];
        }

        usort($jobs, static fn ($a, $b) => ($a['next_run'] < $b['next_run']) ? -1 : 1);

        CLI::table(
            $jobs,
            [
                'Name',
                'Type',
                'Expression',
                'Last Run',
                'Next Run',
            ],
        );
    }
}
