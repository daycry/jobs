<?php

declare(strict_types=1);

namespace Daycry\Jobs\Config;

use CodeIgniter\Config\BaseConfig;
use Daycry\Jobs\Cronjob\Scheduler;
use Daycry\Jobs\Jobs\Command;
use Daycry\Jobs\Loggers\DatabaseHandler as DatabaseLoggerHandler;
use Daycry\Jobs\Loggers\FileHandler as FileLoggerHandler;
class Jobs extends BaseConfig
{
    public array $jobs = [
        'command' => Command::class,
        'shell' => '',
        'closure' => '',
        'event' => '',
        'url' => '',
    ];

    public string $log = 'file'; // 'file' or 'database'
    public array $loggers = [
        'database' => DatabaseLoggerHandler::class,
        'file' => FileLoggerHandler::class,
    ];

    /**
     * Directory
     */
    public string $filePath = WRITEPATH . 'cronJob/';

    /**
     * File Name in folder jobs structure
     */
    public string $fileName = 'jobs';

    /**
     * --------------------------------------------------------------------------
     * Default Timeout (seconds)
     * --------------------------------------------------------------------------
     * A default execution timeout applied to jobs that do not explicitly
     * define one. Null disables global timeout.
     */
    public ?int $defaultTimeout = null; // e.g. 300

    /**
     * Backoff strategy for retries: 'none', 'fixed', 'exponential'
     */
    public string $retryBackoffStrategy = 'none';

    /**
     * Base seconds used when computing backoff delay (first retry delay).
     */
    public int $retryBackoffBase = 5;

    /**
     * Multiplier used for exponential strategy (delay = base * multiplier^(attempt-1)).
     */
    public float $retryBackoffMultiplier = 2.0;

    /**
     * Maximum delay cap (seconds) for any retry.
     */
    public int $retryBackoffMax = 300;

    /**
     * Whether to add +/- random jitter up to 15% of computed delay.
     */
    public bool $retryBackoffJitter = true;

    public function init(Scheduler $scheduler): void
    {
        $scheduler->addJob('command', 'jobs:test')->everyMinute()->singleInstance();
    }
}
