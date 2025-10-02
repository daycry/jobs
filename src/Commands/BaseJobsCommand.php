<?php

declare(strict_types=1);

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

    protected function isActive(): bool
    {
        $cache = service('cache');
        if ($settings = $cache->get('jobs_active')) {
            if($settings->status === 'enabled') {
                return true;
            }
        }

        return false;
    }

    protected function disable(): bool
    {
        $cache = service('cache');
        $settings = new \stdClass();
        $settings->status = 'disabled';
        $settings->updated_at = new DateTime();

        $save = $cache->save('jobs_active', $settings, 0);

        if($save) {
            $this->writeMessage('**** CronJob is now Disabled. ****', 'black', 'green');
            return true;
        }

        return false;
    }

    protected function enable(): bool
    {
        $cache = service('cache');
        $settings = new \stdClass();
        $settings->status = 'enabled';
        $settings->updated_at = new DateTime();

        $save = $cache->save('jobs_active', $settings, 0);

        if($save) {
            $this->writeMessage('**** CronJob is now Enabled. ****', 'black', 'green');
            return true;
        }

        return false;
    }

    protected function tryToEnable()
    {
        $this->writeMessage('**** WARNING: Task running is currently disabled. ****', 'red');
        $this->writeMessage('**** To re-enable tasks run: jobs:cronjob:enable ****', 'black', 'green');
    }

    protected function alreadyEnabled()
    {
        $this->writeMessage('**** CronJob is already Enabled. ****', 'error');
    }

    protected function alreadyDisabled()
    {
        $this->writeMessage('**** CronJob is already Disabled. ****', 'error');
    }

    private function writeMessage($message, $foreground = null, $background = null)
    {
        CLI::newLine(1);
        if ($foreground === 'error') {
            CLI::error($message);
        } else {
            CLI::write($message, $foreground, $background);
        }
        CLI::newLine(1);
    }
}