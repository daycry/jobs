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

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTime;
use Daycry\Jobs\Config\Jobs;
use stdClass;

/**
 * Base command shared by Jobs CLI tools providing enable/disable runtime gating
 * and common helpers for status messaging.
 *
 * A cache flag 'jobs_active' determines whether scheduling/queue processing is allowed.
 */
abstract class BaseJobsCommand extends BaseCommand
{
    protected Jobs $config;
    protected $group = 'Jobs';

    protected function getConfig(): void
    {
        $this->config = config('Jobs');
    }

    /**
     * Check if job processing is globally enabled.
     */
    protected function isActive(): bool
    {
        $cache = service('cache');
        if ($settings = $cache->get('jobs_active')) {
            if ($settings->status === 'enabled') {
                return true;
            }
        }

        return false;
    }

    /**
     * Disable job processing (persists flag in cache).
     */
    protected function disable(): bool
    {
        $cache                = service('cache');
        $settings             = new stdClass();
        $settings->status     = 'disabled';
        $settings->updated_at = new DateTime();

        $save = $cache->save('jobs_active', $settings, 0);

        if ($save) {
            $this->writeMessage('**** CronJob is now Disabled. ****', 'black', 'green');

            return true;
        }

        return false;
    }

    /**
     * Enable job processing (persists flag in cache).
     */
    protected function enable(): bool
    {
        $cache                = service('cache');
        $settings             = new stdClass();
        $settings->status     = 'enabled';
        $settings->updated_at = new DateTime();

        $save = $cache->save('jobs_active', $settings, 0);

        if ($save) {
            $this->writeMessage('**** CronJob is now Enabled. ****', 'black', 'green');

            return true;
        }

        return false;
    }

    /**
     * Inform user that processing is disabled and how to enable it.
     */
    protected function tryToEnable(): void
    {
        $this->writeMessage('**** WARNING: Task running is currently disabled. ****', 'red');
        $this->writeMessage('**** To re-enable tasks run: jobs:cronjob:enable ****', 'black', 'green');
    }

    /**
     * Inform user that the system is already enabled.
     */
    protected function alreadyEnabled(): void
    {
        $this->writeMessage('**** CronJob is already Enabled. ****', 'error');
    }

    /**
     * Inform user that the system is already disabled.
     */
    protected function alreadyDisabled(): void
    {
        $this->writeMessage('**** CronJob is already Disabled. ****', 'error');
    }

    /**
     * Standardized formatted CLI output helper.
     */
    private function writeMessage($message, $foreground = null, $background = null): void
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
