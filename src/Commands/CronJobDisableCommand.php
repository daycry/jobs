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

/**
 * CLI command to disable the cron job runner (sets global inactive flag).
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
    public function run(array $params): void
    {
        if ($this->isActive() === true) {
            $this->disable();
        } else {
            $this->alreadyDisabled();
        }
    }
}
