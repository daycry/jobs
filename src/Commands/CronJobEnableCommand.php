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
 * CLI command to enable the cron job runner (sets global active flag).
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
    public function run(array $params): void
    {
        if ($this->isActive() === false) {
            $this->enable();
        } else {
            $this->alreadyEnabled();
        }
    }
}
