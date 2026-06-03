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
use Daycry\Jobs\Config\Jobs;

/**
 * Base command shared by the Jobs CLI tools. Provides the common command group and a
 * helper to load the package configuration.
 */
abstract class BaseJobsCommand extends BaseCommand
{
    public const SUCCESS = 0;
    public const FAILURE = 1;

    protected Jobs $config;
    protected $group = 'Jobs';

    protected function getConfig(): void
    {
        $this->config = config(Jobs::class);
    }
}
