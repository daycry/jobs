<?php

namespace Daycry\Jobs\Commands;

/**
 * Disables Task Running
 */
class CronJobDisableCommand extends BaseJobsCommand
{
    /**
     * The Command's name
     *
     * @var string
     */
    protected $name = 'jobs:cronjob:disable';

    /**
     * the Command's short description
     *
     * @var string
     */
    protected $description = 'Disables the cronjob runner.';

    /**
     * the Command's usage
     *
     * @var string
     */
    protected $usage = 'jobs:cronjob:disable';

    /**
     * Disables task running
     */
    public function run(array $params)
    {
        if ($this->isActive() === true) {
            $this->disable();
        } else {
            $this->alreadyDisabled();
        }
    }
}
