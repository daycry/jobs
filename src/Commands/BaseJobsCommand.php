<?php

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTime;
use Daycry\CronJob\Config\CronJob as CronJobConfig;
use Daycry\Jobs\Config\Jobs;

/**
 * Base functionality for enable/disable.
 */
abstract class BaseJobsCommand extends BaseCommand
{
    protected Jobs $config;
    protected $group = 'Jobs';

    protected function getConfig()
    {
        $this->config = config('Jobs');
    }
}