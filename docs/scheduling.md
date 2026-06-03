# Scheduling (Cron)

Scheduled jobs are registered in `Config\Jobs::init(Scheduler $scheduler)` and evaluated by the
`jobs:cronjob:run` command, which you wire to your operating system cron to run **every minute**.

For each registered definition the runner evaluates its cron expression against the current time.
A due job is **enqueued** onto its backend when it declares a `queue()`, or **executed inline**
(in the same process) when it has no queue.

## Registering jobs

`init()` receives a `Daycry\Jobs\Cron\Scheduler`. Call `$scheduler->define($handler, $payload)` for
each job; it returns a fluent `JobBuilder` so you can chain frequency, queue and identity helpers.

```php
<?php

namespace Config;

use Daycry\Jobs\Config\Jobs as BaseJobs;
use Daycry\Jobs\Cron\Scheduler;

class Jobs extends BaseJobs
{
    public function init(Scheduler $scheduler): void
    {
        // Enqueued onto the 'reports' queue (a worker runs it).
        $scheduler->define('command', 'app:report')
            ->named('daily-report')
            ->dailyAt('02:00')
            ->queue('reports')
            ->maxRetries(3);

        // No queue -> executed inline by the cron runner itself.
        $scheduler->define('shell', ['ls', '-la'])
            ->named('list-files')
            ->everyMinute()
            ->singleInstance();

        // Closures only run inline (they cannot be serialised to a remote backend).
        $scheduler->define('closure', static fn (): string => 'done')
            ->named('housekeeping')
            ->hourly();

        // Restricted to specific CI4 environments and disabled by default.
        $scheduler->define('url', ['method' => 'GET', 'url' => 'https://example.com/ping'])
            ->named('ping')
            ->everyMinute()
            ->environments('production')
            ->disable();
    }
}
```

## Running the scheduler

Add a single system cron entry that runs the command every minute:

```bash
* * * * * cd /path/to/project && php spark jobs:cronjob:run >> /dev/null 2>&1
```

`jobs:cronjob:run` is idempotent per minute: it only acts on definitions whose cron expression is
due for the current minute.

```bash
# Run the due jobs now
php spark jobs:cronjob:run

# Evaluate the schedule against a frozen time (dry-run / testing)
php spark jobs:cronjob:run -testTime "2026-06-03 02:00:00"
```

## Frequency helpers

`JobBuilder` keeps the five standard cron fields (minute, hour, day-of-month, month, day-of-week) and
recomposes the expression on every call.

| Helper | Cron expression | Meaning |
|--------|-----------------|---------|
| `everyMinute()` | `* * * * *` | Every minute |
| `everyMinute(5)` / `everyXMinutes(5)` | `*/5 * * * *` | Every 5 minutes |
| `hourly()` | `0 * * * *` | Top of every hour |
| `hourlyAt(15)` | `15 * * * *` | 15 minutes past every hour |
| `daily()` | `0 0 * * *` | Every day at midnight |
| `dailyAt('02:30')` | `30 2 * * *` | Every day at 02:30 |
| `weekly()` | `0 0 * * 0` | Sundays at midnight |
| `monthly()` | `0 0 1 * *` | 1st of the month at midnight |
| `quarterly()` | `0 0 1 */3 *` | 1st of Jan/Apr/Jul/Oct at midnight |
| `yearly()` | `0 0 1 1 *` | January 1st at midnight |

For full control, set a raw expression with `cron()`:

```php
$scheduler->define('command', 'app:purge')
    ->named('purge')
    ->cron('30 3 * * 1-5'); // 03:30, Monday to Friday
```

An invalid expression throws a `RuntimeException` at registration time.

## Enabled / disabled

Every definition is enabled by default. Use `disable()` (or `enabled(false)`) to keep a job
registered but skipped, and `enabled(true)` to turn it back on:

```php
$scheduler->define('command', 'app:experimental')
    ->named('experimental')
    ->everyMinute()
    ->disable();
```

The cron runner short-circuits on disabled definitions before evaluating their schedule.

## Environments

Restrict a job to specific CodeIgniter environments. An empty list (the default) imposes no
restriction. `environments()` accepts a variadic list or a single array:

```php
$scheduler->define('command', 'app:report')->environments('production');
$scheduler->define('command', 'app:debug')->environments(['development', 'testing']);
```

The runner compares the active `ENVIRONMENT` constant against this list and skips the job when it
does not match.

## Dependencies and execution order

`dependsOn()` declares the **names** of jobs that must run first. The scheduler sorts all
definitions topologically by their dependencies before evaluation, so a dependency always precedes
its dependents within a single cron run.

```php
$scheduler->define('command', 'app:extract')->named('extract')->dailyAt('01:00')->queue('etl');
$scheduler->define('command', 'app:transform')->named('transform')->dailyAt('01:00')
    ->queue('etl')->dependsOn('extract');
$scheduler->define('command', 'app:load')->named('load')->dailyAt('01:00')
    ->queue('etl')->dependsOn('transform');
```

Notes:

- The order only affects the sequence in which due definitions are evaluated within one
  `jobs:cronjob:run` invocation. Jobs that declare a `queue()` are still enqueued (a worker runs
  them asynchronously); dependencies do not block on a queued job's actual completion.
- Referencing an unknown dependency name, or introducing a cycle, throws a `RuntimeException`.

## Single instance

`singleInstance()` marks a definition as non-overlapping. The package ships a
`Daycry\Jobs\Execution\SingleInstanceLock` (cache-backed, ownership-token based) to back this flag.
See [Advanced](advanced.md#single-instance) for the lock semantics and best-effort caveats.

## Inline vs enqueued — quick reference

| Definition declares | Behaviour in `jobs:cronjob:run` |
|---------------------|---------------------------------|
| `->queue('name')` | Enqueued onto the configured backend; a worker (`jobs:queue:work`) runs it |
| no queue | Executed inline by the cron runner via `JobRuntime` (one attempt) |

See [Queues & Backends](QUEUES.md) for the worker, lease semantics and backend configuration.
