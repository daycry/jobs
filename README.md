[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.com/donate?business=SYC5XDT23UZ5G&no_recurring=0&item_name=Thank+you%21&currency_code=EUR)

# Codeigniter Jobs

[![Build status](https://github.com/daycry/jobs/actions/workflows/php.yml/badge.svg?branch=master)](https://github.com/daycry/jobs/actions/workflows/php.yml)
[![Coverage status](https://coveralls.io/repos/github/daycry/jobs/badge.svg?branch=master)](https://coveralls.io/github/daycry/jobs?branch=master)
[![Downloads](https://poser.pugx.org/daycry/jobs/downloads)](https://packagist.org/packages/daycry/jobs)
[![GitHub release (latest by date)](https://img.shields.io/github/v/release/daycry/jobs)](https://packagist.org/packages/daycry/jobs)
[![GitHub stars](https://img.shields.io/github/stars/daycry/jobs)](https://packagist.org/packages/daycry/jobs)
[![GitHub license](https://img.shields.io/github/license/daycry/jobs)](https://github.com/daycry/jobs/blob/master/LICENSE)

Advanced job scheduling and queue processing for CodeIgniter 4. Combines cron-style recurring tasks, ad‑hoc queued jobs, multiple backends (Redis, Database, Beanstalk, Azure Service Bus, Sync), structured logging, retry strategies and metrics hooks in a single cohesive package.

<p align="center">
<a href="#installation">Installation</a> ·
<a href="#quick-start">Quick Start</a> ·
<a href="#features">Features</a> ·
<a href="docs/CONFIGURATION.md">Configuration</a> ·
<a href="docs/LOGGING.md">Logging</a> ·
<a href="docs/RETRIES.md">Retries</a> ·
<a href="docs/ATTEMPTS.md">Attempts</a> ·
<a href="docs/QUEUES.md">Queues</a> ·
<a href="docs/COMMANDS.md">CLI Commands</a> ·
<a href="docs/ARCHITECTURE.md">Architecture</a>
</p>
<p align="center">
<a href="docs/METRICS.md">Metrics</a>
</p>

---

## Features
- Cron style scheduler (minute level) with enable/disable operations.
- Unified Job abstraction (command, shell, closure, event, URL) via a consistent API.
- Multiple queue backends: Redis, Database, Beanstalk, Azure Service Bus, Sync (inline execution).
- Requeue & retry strategies (none, fixed, exponential + optional jitter & cap) with normalized attempt semantics.
- Structured execution logging (file or database) including: executionId, queue, source, attempt, payload hash, output length, retry strategy.
- Recursive payload/output/error masking of sensitive keys.
- Execution metrics hook interface (Prometheus friendly) for counters & timings.
- Pluggable pruning (max logs per job) and payload hashing for dedup/forensics.
- Clean separation of concerns (JobEnvelope transport + Job domain object + RequeueHelper lifecycle).

## Installation
```bash
composer require daycry/jobs
```
Publish config (if using CodeIgniter's publisher).

```php
php spark jobs:publish
```

Run migrate command to create the necessary database tables.

```bash
php spark migrate -all
```

## Defining Schedules

Jobs are configured with the `app/Config/Jobs.php` config file, inside of the `init()` method.
Lets start with a simple example:

```php
$scheduler->command('jobs:test')->named('enabled')->everyMinute()->singleInstance()->priority(5)->enqueue();

$scheduler->command('jobs:test')->named('enabled')->daily( '11:00 pm' )->singleInstance()->notifyOnCompletion();

$scheduler->command('jobs:test')->named('disabled')->everyMinute()->singleInstance()->disable();

$scheduler->shell('ls')->named('shell_test')->hourly()->singleInstance();
$scheduler->closure(function() {
    // Your closure code here
    return 'Closure executed successfully!';
})->named('closure_test')->everyMinute()->singleInstance();

$scheduler->event(name: 'user.registered', data: ['user_id' => 123])->named('event_test')->everyMinute()->singleInstance();

$scheduler->url(url: 'https://google.es', method: 'GET', options: ['headers' => ['Accept' => 'application/html']]);

```

### Frequency Options

There are a number of ways available to specify how often the task is called.


| Method                        | Description                                                           |
|:------------------------------|:----------------------------------------------------------------------|
| ->cron('* * * * *')           | Run on a custom cron schedule.                                        |
| ->daily('4:00 am')            | Runs daily at 12:00am, unless a time string is passed in.             |    
| ->hourly() / ->hourly(15)     | Runs at the top of every hour or at specified minute.                 |
| ->everyFiveMinutes()          | Runs every 5 minutes (12:00, 12:05, 12:10, etc)                       |
| ->everyFifteenMinutes()       | Runs every 15 minutes (12:00, 12:15, etc)                             |
| ->everyThirtyMinutes()        | Runs every 30 minutes (12:00, 12:30, etc)                             |
| ->sundays('3:15 am')           | Runs every Sunday at midnight, unless time passed in.                 |
| ->mondays('3:15 am')           | Runs every Monday at midnight, unless time passed in.                 |
| ->tuesdays('3:15 am')          | Runs every Tuesday at midnight, unless time passed in.                |
| ->wednesdays('3:15 am')        | Runs every Wednesday at midnight, unless time passed in.              |
| ->thursdays('3:15 am')         | Runs every Thursday at midnight, unless time passed in.               |
| ->fridays('3:15 am')           | Runs every Friday at midnight, unless time passed in.                 |
| ->saturdays('3:15 am')         | Runs every Saturday at midnight, unless time passed in.               |
| ->monthly('12:21 pm')          | Runs the first day of every month at 12:00am unless time passed in.   |
| ->quarterly('5:00 am')         | Runs the first day of each quarter (Jan 1, Apr 1, July 1, Oct 1)      |
| ->yearly('12:34 am')           | Runs the first day of the year.                                       |
| ->weekdays('1:23 pm')          | Runs M-F at 12:00 am unless time passed in.                           |
| ->weekends('2:34 am')          | Runs Saturday and Sunday at 12:00 am unless time passed in.           |
| ->environments('local', 'prod')   | Restricts the task to run only in the specified environments      |
| ->everyHour(3, 15)            | Runs every 3 hours at XX:15.                                          |
| ->betweenHours(6,12)          | Runs between hours 6 and 12.                                          |
| ->hours([0,10,16])            | Runs at hours 0, 10 and 16.                                           |
| ->everyMinute(20)             | Runs every 20 minutes.                                                |
| ->betweenMinutes(0,30)        | Runs between minutes 0 and 30.                                        |
| ->minutes([0,20,40])          | Runs at specific minutes 0,20 and 40.                                 |
| ->days([0,3])                 | Runs only on Sunday and Wednesday  ( 0 is Sunday , 6 is Saturday )    |
| ->daysOfMonth([1,15])         | Runs only on days 1 and 15.                                           |
| ->months([1,7])               | Runs only on January and July.                                        |


Run worker (example with Redis worker configured in Jobs config):
```bash
php spark jobs:cronjob:run
```

## Job Dependencies

You can now define dependencies between jobs. A job will only run after all the jobs it depends on have been executed successfully in the same run. This is useful for workflows where certain tasks must be completed before others start.

## Usage Example

```php
$schedule->command('generate:report')->everyDay()->named('generate-report');
$schedule->command('send:report')->everyDay()->dependsOn('generate-report');
```

You can also specify multiple dependencies:

```php
$schedule->command('archive:report')->everyDay()->dependsOn(['generate-report', 'send-report']);
```

## Job Retries and Timeout

You can now configure automatic retries and a timeout for each job:

```php
$schedule->command('unstable:task')->maxRetries(3)->timeout(60); // Retries up to 3 times, 60s timeout
```
- `maxRetries(int)` sets how many times the job will be retried if it fails.
- `timeout(int)` sets the maximum execution time in seconds (enforced at the job logic level).

## Direct Queueing (Services::queueJob)

You can enqueue a job immediately without defining it in the scheduler using the service helper:

```php
use Daycry\Jobs\Config\Services;
use Daycry\Jobs\Job;

$id = Services::queueJob(
    job: 'command',            // Handler key as defined in Jobs config
    payload: 'jobs:test',      // Payload for the handler
    queue: 'default',          // Optional queue; if null first configured queue is used
    configure: function(Job $j) {
        $j->named('on_demand_test')->priority(3)->singleInstance();
    }
);
```

### Scheduling with $when
The last argument allows delayed/scheduled enqueueing:

| Type                          | Example                                 | Meaning |
|-------------------------------|------------------------------------------|---------|
| `int`                         | `30`                                     | Run ~30 seconds from now |
| `string`                      | `'2025-12-01 10:30:00'`                  | Parsed by `DateTime` |
| `DateTimeInterface`           | `new DateTime('+5 minutes')`            | Use directly |
| `CodeIgniter\I18n\Time`      | `Time::now()->addMinutes(2)`             | Converted to DateTime |
| `null`                        | `null`                                   | Enqueue immediately |

Example with delay:
```php
Services::queueJob('command', 'jobs:notify', 'default', function(Job $j) {
    $j->named('delayed_notify');
}, 120); // 120 seconds from now
```

Example with specific datetime:
```php
Services::queueJob('command', 'jobs:midnight:task', 'default', function(Job $j) {
    $j->named('run_at_midnight');
}, 'tomorrow 00:00:00');
```

### Return Value
Returns the backend queue identifier (string) from the underlying worker after calling `push()`.

### Differences vs Scheduler
| Aspect              | Scheduler (`$scheduler->command(...)`) | `Services::queueJob(...)` |
|---------------------|----------------------------------------|---------------------------|
| Definition Location | Cron-style config time expressions     | Immediate ad‑hoc          |
| Frequency           | Recurring or complex timing            | One-off (optionally delayed) |
| Source field        | `cron`                                 | `queue`                   |
| Fluent Frequency API| Yes (everyMinute, daily, etc.)         | Not applicable            |
| Callback Support    | Yes (define on Job)                    | Yes (define in configure) |

### Notes
* Throws `JobException` if the handler key is invalid.
* The `$configure` closure MUST NOT call `push()` manually (the helper handles it).
* You can still attach a callback in the configure step:
```php
Services::queueJob('command', 'jobs:process', 'default', function(Job $j) {
    $j->named('process_now')
      ->setCallbackJob(fn(Job $p) => new Job(job: 'closure', payload: fn() => 'done'));
});
```
* For recurring tasks prefer the scheduler; use this helper for on‑demand / API-triggered jobs.

### Advanced Example (Retries, Timeout, Callback, Delay)
```php
use Daycry\Jobs\Config\Services;
use Daycry\Jobs\Job;
use CodeIgniter\I18n\Time;

$queueId = Services::queueJob(
    job: 'command',
    payload: 'jobs:import:users',
    queue: 'high',
    configure: function(Job $job) {
        $job
            ->named('import_users_bulk')            // Friendly name
            ->priority(8)                           // Higher priority (depends on backend semantics)
            ->singleInstance()                      // Prevent concurrent duplicates
            ->maxRetries(5)                         // Automatic retry attempts
            ->timeout(180)                          // Soft timeout (seconds)
            ->setCallbackJob(
                builder: function(Job $parent) {
                    // Callback runs only on success and is enqueued separately
                    return (new Job(job: 'command', payload: 'jobs:notify:import:done'))
                        ->named('notify_import_done')
                        ->enqueue('notifications')
                        ->setCallbackJob(function(Job $p) {
                            // Second-level callback (chained) executed inline (no queue)
                            return new Job(job: 'closure', payload: fn () => 'chained inline log');
                        }, [
                            'on' => 'always',
                            'inherit' => ['name','attempts'],
                        ]);
                },
                options: [
                    'on'         => 'success',             // Only when import succeeded
                    'inherit'    => ['output','error','attempts','name','source'],
                    'allowChain' => true,                  // Permit the second-level callback
                ]
            );
    },
    when: Time::now()->addMinutes(2) // Delay execution by 2 minutes
);

// $queueId contains the underlying worker's identifier for this enqueued job
```

What this does:
* Schedules the import job to start in ~2 minutes.
* Retries up to 5 times with default backoff strategy on failure.
* Aborts logic if it exceeds 180 seconds (internally enforced) per attempt.
* After a successful import, enqueues a notification job into the `notifications` queue.
* The notification job defines a chained inline closure callback (because it has no queue) for a lightweight follow-up.
* Inherited meta fields from the parent import are injected into the first callback's payload under `parentOutput`, `parentError`, etc.

## Callback Jobs (Post-Execution)

You can attach a follow-up Job that is created and either executed inline or enqueued automatically after the parent Job finishes.

Define a callback using `setCallbackJob(callable $builder, array $options = [])`. The builder receives the parent `Job` and must return a configured child `Job` (DO NOT call `push()` inside the builder; just configure it). Whether it runs inline or is queued depends solely on whether the child has a queue assigned (e.g. via `->enqueue()` or `->setQueue()`):

```php
$job = (new Job(job: 'command', payload: 'jobs:process:data'))
    ->named('process_data')
    ->enqueue('default')
    ->setCallbackJob(function(Job $parent) {
        // Inline callback (no queue)
        return new Job(job: 'closure', payload: fn () => log_message('info', 'Finished ' . $parent->getName()));
    });

$queued = (new Job(job: 'command', payload: 'jobs:sync'))
    ->enqueue('default')
    ->setCallbackJob(function(Job $p) {
        // This callback will be enqueued because we assign a queue
        return (new Job(job: 'command', payload: 'jobs:notify:done'))
            ->enqueue('callbacks')
            ->named('notify_done');
    }, [
        'on'        => 'success',      // success | failure | error | always (default)
        'inherit'   => ['output','error','attempts','name','source'],
        'allowChain'=> true,           // allow the callback itself to define another callback
    ]);
```

### Options
| Key        | Type                              | Default   | Description |
|------------|-----------------------------------|-----------|-------------|
| `on` / `filter` | string (`success`,`failure`,`error`,`always`) | `always` | When to fire the callback. `error` is an alias of `failure`. |
| `inherit`  | string[]                          | `['output','error']` | Parent fields to inject into child payload meta (`parentOutput`, `parentError`, `parentAttempts`, `parentName`, `parentSource`). |
| `allowChain` | bool                            | `false`   | Permit the callback job to have its own callback (chaining). |

### Inheritance Behavior
If the child job payload is:
- An array: a `meta` key is merged/created.
- An object (not a Closure): properties are added.
- A scalar / Closure: meta is wrapped as `['data'=>payload,'meta'=>...]` (Closure case: meta skipped to avoid mutation).

#### Valid `inherit` keys
Only the following parent fields are recognized; any others are ignored silently:

| Inherit Key | Parent Source Method/Property | Injected Meta Key |
|-------------|-------------------------------|-------------------|
| `output`    | `$parent->getOutput()`        | `parentOutput`    |
| `error`     | `$parent->getError()`         | `parentError`     |
| `attempts`  | `$parent->getAttempts()`      | `parentAttempts`  |
| `name`      | `$parent->getName()`          | `parentName`      |
| `source`    | `$parent->getSource()`        | `parentSource`    |

Notes:
* Default is `['output','error']` if you omit `inherit`.
* Duplicated keys are harmless (final value just overwrites itself).
* Missing/empty parent values result in `null` (or the key may be skipped depending on execution state) but never throw.
* Use an empty array `[]` to disable inheritance entirely.
* Closure payloads skip meta injection (to avoid mutating the Closure), all other payload shapes follow the behavior described above.

#### Inheritance Examples

Disable inheritance entirely:
```php
$job->setCallbackJob(function(Job $p) {
    return new Job(job: 'closure', payload: fn() => 'no inheritance');
}, [
    'inherit' => []
]);
```

Inherit only parent name and attempts (useful for conditional logic in the child):
```php
$job->setCallbackJob(function(Job $p) {
    return new Job(job: 'closure', payload: function () use ($p) {
        // You can read parentName / parentAttempts from injected meta
        return 'child executed';
    });
}, [
    'inherit' => ['name','attempts']
]);
```

### Chaining
If `allowChain` is `true`, and the callback job itself defines another callback, it will dispatch recursively (inline chain). For enqueued callbacks, further chaining occurs when the worker later executes the child job.

### Inline vs Enqueued Summary
| Child Job Config | Outcome |
|------------------|---------|
| No queue assigned | Executed immediately after parent completion |
| `->enqueue()` or `->setQueue()` used | Enqueued (executed later by queue worker) |

> Note: When using the `sync` worker backend, the job (and any inline callback chain) is executed immediately in the same PHP process using the full lifecycle (retries and callback dispatch happen inline). This is helpful for local debugging, but means long or blocking work will block the request. Switch to a real async backend (redis, database, etc.) for production.

### Filtering Examples
```php
// Only when parent succeeds
$job->setCallbackJob(fn(Job $p) => new Job(job: 'closure', payload: fn() => 'OK'), ['on' => 'success']);

// Only when parent fails (alias 'error')
$job->setCallbackJob(fn(Job $p) => new Job(job: 'closure', payload: fn() => 'HandleFail'), ['on' => 'error']);

// Always (default)
$job->setCallbackJob(fn(Job $p) => new Job(job: 'closure', payload: fn() => 'Always')); 
```

### Notes
* Do not call `push()` inside the builder; the system decides inline vs enqueue by presence of queue.
* The callback child sets `source('callback')` automatically.
* Payload output may be truncated by global logging config (`maxOutputLength`).
* Future: you can extend to custom meta injection or depth limits.



## Configuration
See [Configuration](docs/CONFIGURATION.md) for all options (queues list, workers map, retry strategy, logging drivers, sensitive keys, backoff parameters, timeouts, service bus, beanstalk, database, email notifications).

## Logging
Structured logging records are emitted per execution when `logPerformance` is enabled. You can choose file or database drivers. See [Logging docs](docs/LOGGING.md) for format, masking, pruning and [how to create a custom handler](docs/LOGGING.md#implementing-a-custom-logger-handler).

## Attempts Semantics
Attempts start at 0 (never executed). Each completed execution cycle (success OR failure) increments attempts exactly once at the start of finalization. On failure the job is requeued retaining its attempt count for retry policy evaluation. See [Attempts](docs/ATTEMPTS.md).

## Retry Backoff
Set `retryBackoffStrategy` to `none`, `fixed`, or `exponential`. Configure `retryBackoffBase`, `retryBackoffMultiplier`, `retryBackoffMax` and `retryBackoffJitter`. See [Retries](docs/RETRIES.md).

## Queue Backends
Backends are configured in `Jobs::$workers`. Switch active backend via `Jobs::$worker`. See [Queues](docs/QUEUES.md) for notes on each driver and capabilities.

## CLI Commands
Common commands:
- `jobs:cronjob:run` run a cron job worker loop.
- `jobs:cronjob:list` list all registered cron jobs.
- `jobs:cronjob:history` show recent executions (supports extended flag).
- `jobs:cronjob:enable <name>` / `jobs:cronjob:disable <name>` toggle.
- `jobs:queue:run` run a queue worker.

See [CLI Commands](docs/COMMANDS.md).

## Notifications

If you want to receive notifications by email, simply configure the Codeigniter Email library.

Then, in your job definition, you can use the following methods:
```php
$scheduler->command('jobs:test')->named('enabled')->everyMinute()->singleInstance()->notifyOnSuccess()->notifyOnFailure();
$scheduler->command('jobs:test')->named('enabled')->everyMinute()->singleInstance()->notifyOnCompletion();  
```


## Metrics
The jobs system exposes a lightweight, pluggable metrics layer. Provide your own implementation of
`MetricsCollectorInterface` to export counters and timings to Prometheus / StatsD / etc.

Built‑in counters currently emitted by `RequeueHelper`:
* `jobs_succeeded`
* `jobs_failed`
* `jobs_requeued`
* `jobs_timed_out` (reserved for when a timeout hook is added)

Quick example:
```php
use Daycry\Jobs\Metrics\InMemoryMetricsCollector;
use Daycry\Jobs\Queues\RequeueHelper;

$metrics  = new InMemoryMetricsCollector();
$requeue  = new RequeueHelper($metrics); // core counters now increment

// Custom timing after a run (example placement inside your worker loop)
$metrics->observe('job_duration_seconds', 0.42, ['queue' => 'default', 'job' => 'jobs:import']);
```

See the full guide with naming conventions, custom examples (duration, latency, business KPIs) and a Prometheus adapter skeleton in [Metrics Documentation](docs/METRICS.md).

### Configuration
Set a custom collector (must implement `MetricsCollectorInterface`) in your `Jobs` config:
```php
public ?string $metricsCollector = \App\Metrics\PrometheusCollector::class;
```
Disable metrics entirely:
```php
public ?string $metricsCollector = null; // all metrics become no-ops
```
The queue worker (`jobs:queue:run`) auto-instantiates the configured class (no-arg constructor) and falls back to the in-memory collector if misconfigured.

## Architecture
High-level components: Job (domain), JobEnvelope (transport across backends), Queue implementations, RequeueHelper (lifecycle & attempts), Logger (structured execution emission), Retry policies (delay computation). See [Architecture](docs/ARCHITECTURE.md).

## Testing
Package includes PHPUnit test suite for queues, logging, retries, masking, scheduling. Use `composer test`.

## License
MIT © Daycry

----
For deeper sections refer to the `docs/` directory.
