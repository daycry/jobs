<?php

namespace Daycry\Jobs\Commands;

/**
 * Enables Task Running
 */
class CronJobEnableCommand extends BaseJobsCommand
{
    /**
     * The Command's name
     *
     * @var string
     */
    protected $name = 'jobs:cronjob:enable';

    /**
     * the Command's short description
     *
     * @var string
     */
    protected $description = 'Enables the cronjob runner.';

    /**
     * the Command's usage
     *
     * @var string
     */
    protected $usage = 'jobs:cronjob:enable';

    /**
     * Enables task running
     */
    public function run(array $params)
    {
        if ($this->isActive() === false) {
            $this->enable();
        } else {
            $this->alreadyEnabled();
        }
    }
}
