<?php

declare(strict_types=1);

namespace Daycry\Jobs\Config;

use CodeIgniter\Config\BaseConfig;
use Daycry\Jobs\Cronjob\Scheduler;
use Daycry\Jobs\Jobs\ClosureJob;
use Daycry\Jobs\Jobs\CommandJob;
use Daycry\Jobs\Jobs\EventJob;
use Daycry\Jobs\Jobs\ShellJob;
use Daycry\Jobs\Jobs\UrlJob;
use Daycry\Jobs\Loggers\DatabaseHandler as DatabaseLoggerHandler;
use Daycry\Jobs\Loggers\FileHandler as FileLoggerHandler;
use Daycry\Jobs\Queues\BeanstalkQueue;
use Daycry\Jobs\Queues\DatabaseQueue;
use Daycry\Jobs\Queues\RedisQueue;
use Daycry\Jobs\Queues\ServiceBusQueue;
use Daycry\Jobs\Queues\SyncQueue;
class Jobs extends BaseConfig
{
    public array $jobs = [
        'command' => CommandJob::class,
        'shell' => ShellJob::class,
        'closure' => ClosureJob::class,
        'event' => EventJob::class,
        'url' => UrlJob::class,
    ];

    public bool $logPerformance = true;
    public int $maxLogsPerJob = 3;

    /**
     * Maximum number of characters from job output to store (null = unlimited)
     */
    public ?int $maxOutputLength = null;

    public string $log = 'database'; // 'file' or 'database'
    public array $loggers = [
        'database' => DatabaseLoggerHandler::class,
        'file' => FileLoggerHandler::class,
    ];

    public string $filePath = WRITEPATH . 'jobs/';
    public ?string $databaseGroup = null;
    public string $tableName = 'jobs';

    /**
     * --------------------------------------------------------------------------
     * Default Timeout (seconds)
     * --------------------------------------------------------------------------
     * A default execution timeout applied to jobs that do not explicitly
     * define one. Null disables global timeout.
     */
    public ?int $defaultTimeout = null; // in seconds e.g. 2

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

    public array|string $queues        = 'default,dummy';

    public string $worker              = 'database';

    public array $database = [
        'group' => null,
        'table' => 'queues',
    ];

    public array $workers              = [
        'sync'       => SyncQueue::class,
        'beanstalk'  => BeanstalkQueue::class,
        'redis'      => RedisQueue::class,
        'serviceBus' => ServiceBusQueue::class,
        'database'   => DatabaseQueue::class,
    ];

    public function init(Scheduler $scheduler): void
    {
        $scheduler->command('jobs:test')->named('enabled')->everyMinute()->singleInstance()->priority(5)->enqueue();
        //$scheduler->command('jobs:test')->named('enabled')->everyMinute()->singleInstance()->notifyOnCompletion();
        //$scheduler->command('jobs:test')->named('disabled')->everyMinute()->singleInstance()->disable();
        /*$scheduler->shell('ls')->named('shell_test')->everyMinute()->singleInstance();
        $scheduler->closure(function() {
            // Your closure code here
            return 'Closure executed successfully!';
        })->named('closure_test')->everyMinute()->singleInstance();
        $scheduler->event(name: 'user.registered', data: ['user_id' => 123])->named('event_test')->everyMinute()->singleInstance();
        $scheduler->url(url: 'https://google.es', method: 'GET', options: ['headers' => ['Accept' => 'application/html']]);*/
    }

    public string $emailNotificationView = 'Daycry\Jobs\Views\email_notification';
    public string $from       = 'your@example.com';
    public string $fromName   = 'CronJob';
    public string $to         = 'your@example.com';
    public string $toName     = 'User';
}
