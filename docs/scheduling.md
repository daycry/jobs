# Scheduling (Cron)

The v3 scheduler lets you register recurring jobs in code and have them evaluated once a minute by a
single system cron entry. Scheduled jobs are registered in `Config\Jobs::init(Scheduler $scheduler)`
and evaluated by the `jobs:cronjob:run` command. For each registered definition the runner checks
the cron expression against the current minute and, when due, either **enqueues** the job onto its
backend (when it declares a `queue()`) or **executes it inline** in the same process (when it has no
queue).

This is the v3 replacement for the legacy Scheduler. Definitions are immutable
`JobDefinition` value objects produced by the same fluent `JobBuilder` used for ad-hoc dispatch, so
everything you know from [Queues & Backends](QUEUES.md) applies here too.

## Registering jobs

Override `init()` in your application's `Config\Jobs` (which extends
`Daycry\Jobs\Config\Jobs`). It receives a `Daycry\Jobs\Cron\Scheduler`. Call
`$scheduler->define($handler, $payload)` once per job; it returns a fluent `JobBuilder` so you can
chain frequency, queue and identity helpers.

```php
<?php

namespace Config;

use Daycry\Jobs\Config\Jobs as BaseJobs;
use Daycry\Jobs\Cron\Scheduler;

class Jobs extends BaseJobs
{
    public function init(Scheduler $scheduler): void
    {
        // Enqueued onto the 'reports' queue — a worker (jobs:queue:work) runs it asynchronously.
        $scheduler->define('command', 'app:report')
            ->named('daily-report')
            ->dailyAt('02:00')
            ->queue('reports')
            ->maxRetries(3);

        // No queue() -> executed INLINE by the cron runner itself, one attempt.
        $scheduler->define('shell', ['ls', '-la'])
            ->named('list-files')
            ->everyMinute()
            ->singleInstance();

        // Closures can only run inline (they cannot be serialised to a remote backend).
        $scheduler->define('closure', static fn (): string => 'done')
            ->named('housekeeping')
            ->hourly();

        // Restricted to a specific CI4 environment and registered but disabled.
        $scheduler->define('url', ['method' => 'GET', 'url' => 'https://example.com/ping'])
            ->named('ping')
            ->everyMinute()
            ->environments('production')
            ->disable();
    }
}
```

> **Note:** `closure` jobs run **inline only**. A closure cannot be serialized onto a remote backend,
> so do not give a `closure` definition a `queue()`. Use `command`, `shell`, `url` or `event` for
> queued work. See the handler reference in [Configuration](CONFIGURATION.md#handlers).

## Running the scheduler

`jobs:cronjob:run` must run **every minute**. Add one entry to your operating system's crontab:

```bash
* * * * * cd /path/to/project && php spark jobs:cronjob:run >> /dev/null 2>&1
```

The command itself is the only thing the OS cron drives; it evaluates all registered definitions and
acts only on those due for the current minute.

```bash
# Run the due jobs now
php spark jobs:cronjob:run

# Evaluate the schedule against a frozen time (deterministic dry-run / testing)
php spark jobs:cronjob:run -testTime "2026-06-03 02:00:00"
```

The runner evaluates cron expressions using `Config\App::$appTimezone`, so schedules are interpreted
in your application timezone rather than UTC or the server's locale.

> **Note:** The runner evaluates the schedule on **every** invocation, out of the box — there is no
> global on/off flag to set first. Control which jobs run on a **per-job** basis with `enabled()` /
> `disable()` and `environments()` on the definition (see [Enabled / disabled](#enabled--disabled)
> and [Environments](#environments) below).

## Frequency helpers

`JobBuilder` keeps the five standard cron fields (minute, hour, day-of-month, month, day-of-week) and
recomposes the expression on every call, so helpers compose predictably.

| Helper | Resulting cron | Meaning |
|--------|----------------|---------|
| `everyMinute()` | `* * * * *` | Every minute |
| `everyMinute(5)` | `*/5 * * * *` | Every 5 minutes |
| `everyXMinutes(5)` | `*/5 * * * *` | Every 5 minutes (explicit alias) |
| `hourly()` | `0 * * * *` | Top of every hour |
| `hourlyAt(15)` | `15 * * * *` | 15 minutes past every hour |
| `daily()` | `0 0 * * *` | Every day at midnight |
| `dailyAt('02:30')` | `30 2 * * *` | Every day at 02:30 |
| `weekly()` | `0 0 * * 0` | Sundays at midnight |
| `monthly()` | `0 0 1 * *` | 1st of the month at midnight |
| `quarterly()` | `0 0 1 */3 *` | 1st of Jan/Apr/Jul/Oct at midnight |
| `yearly()` | `0 0 1 1 *` | January 1st at midnight |

> **Note:** `dailyAt('HH:MM')` normalises the time without leading zeros, so `'02:30'` yields the
> hour field `2` (matching standard crontab output), not `02`.

For full control, set a raw expression with `cron()`:

```php
$scheduler->define('command', 'app:purge')
    ->named('purge')
    ->cron('30 3 * * 1-5'); // 03:30, Monday to Friday
```

> **Warning:** `cron()` validates its argument and throws a `RuntimeException` at registration time
> for an invalid expression (and the expression must have exactly five fields). Catch configuration
> errors in CI rather than in production cron.

## Queued vs inline — the rule

The single rule that decides how a due job runs:

| Definition declares | Behaviour in `jobs:cronjob:run` |
|---------------------|---------------------------------|
| `->queue('name')` | **Enqueued** onto the configured default backend (`Config\Jobs::$worker`); a separate worker (`jobs:queue:work`) runs it asynchronously. |
| no `queue()` | **Executed inline** by the cron runner via `JobRuntime` — one attempt, in the cron process. |

```php
// Queued: cron enqueues it, jobs:queue:work runs it (subject to maxRetries, backoff, the reaper).
$scheduler->define('command', 'app:report')->named('report')->dailyAt('02:00')->queue('reports');

// Inline: cron runs it directly, blocking the cron process for the duration of one attempt.
$scheduler->define('command', 'app:vacuum')->named('vacuum')->daily();
```

> **Note:** Queued cron jobs always enqueue onto the **default** backend (`$worker`); the scheduler
> does not pick a per-job backend. Inline jobs do not get retries or backoff — a failed inline job is
> simply a failed `ExecutionResult` for that tick.

## Enabled / disabled

Every definition is enabled by default. Use `disable()` (alias of `enabled(false)`) to keep a job
registered but skipped, and `enabled(true)` to turn it back on:

```php
$scheduler->define('command', 'app:experimental')
    ->named('experimental')
    ->everyMinute()
    ->disable();
```

The cron runner short-circuits on disabled definitions **before** evaluating their schedule.

## Environments

Restrict a job to specific CodeIgniter environments. An empty list (the default) imposes no
restriction. `environments()` accepts a variadic list or a single array:

```php
$scheduler->define('command', 'app:report')->named('report')->environments('production');
$scheduler->define('command', 'app:debug')->named('debug')->environments(['development', 'testing']);
```

The runner compares the active `ENVIRONMENT` constant against this list and skips the job when it
does not match.

## Dependencies and execution order

`dependsOn()` declares the **names** of jobs that must come first. Before evaluating any schedule,
the scheduler sorts all definitions topologically (`Scheduler::getExecutionOrder()`), so a dependency
is always evaluated before its dependents within a single cron run. Names default from the handler +
a hash of the payload when `named()` is not set; declare `named()` explicitly to reference a job in
`dependsOn()`.

```php
$scheduler->define('command', 'app:extract')
    ->named('extract')->dailyAt('01:00')->queue('etl');

$scheduler->define('command', 'app:transform')
    ->named('transform')->dailyAt('01:00')->queue('etl')->dependsOn('extract');

$scheduler->define('command', 'app:load')
    ->named('load')->dailyAt('01:00')->queue('etl')->dependsOn('transform');
```

Notes and caveats:

- The order only affects the **sequence in which due definitions are evaluated** within one
  `jobs:cronjob:run` invocation. It is **not** a workflow engine: a job that declares a `queue()` is
  still merely enqueued (a worker runs it later), so dependents do **not** wait for a queued
  dependency's actual completion.
- Referencing an unknown dependency name, or introducing a cycle, throws a `RuntimeException` when
  the order is computed.

## Single instance

`singleInstance()` marks a definition as non-overlapping. The package backs this flag with
`Daycry\Jobs\Execution\SingleInstanceLock` (cache-backed, ownership-token based), wired into
`JobRuntime`: if the same named job is already running, the new attempt fails fast with
`"single-instance job '<name>' is already running"`. See
[Single instance](advanced.md#single-instance) for the lock semantics and best-effort caveats.

```php
$scheduler->define('command', 'app:long-import')
    ->named('long-import')
    ->everyMinute()
    ->singleInstance(); // a second overlapping run is rejected instead of running concurrently
```

## Missed runs

Scheduling is **best-effort per tick**. `jobs:cronjob:run` evaluates whether each expression is due
for the *current* minute and acts on it; it does **not** keep a history of past runs or back-fill
ticks that were missed.

> **Warning:** If the OS cron does not fire (the machine was down, cron was paused, or a previous
> inline run overran the minute window), the missed occurrences are **not replayed**. For jobs where
> a missed window is unacceptable, prefer a queued definition (so the work is durable once enqueued)
> and keep inline cron jobs short. Do not run two `jobs:cronjob:run` processes for the same schedule
> concurrently, or due jobs may be dispatched twice within the same minute.

## See also

- [Queues & Backends](QUEUES.md) — the worker, lease semantics and backend configuration for queued
  cron jobs.
- [CLI Commands](COMMANDS.md#jobscronjobrun) — the full `jobs:cronjob:run` reference.
- [Configuration](CONFIGURATION.md#scheduled-jobs-init) — registering jobs via `init()`.
- [Operations](operations.md) — running the worker that consumes queued cron jobs in production.
