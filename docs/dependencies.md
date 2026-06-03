# Job Dependencies

Scheduled jobs can declare that they depend on other scheduled jobs. The cron runner uses those
declarations to compute a **topological execution order** so a job never runs before the jobs it
depends on. Dependencies are a *scheduling-order* concept: they are resolved within a single
`jobs:cronjob:run` invocation, by `Daycry\Jobs\Cron\Scheduler`.

This page covers how to declare dependencies, how the ordering is computed, and the failure modes
(unknown dependencies and cycles). For the broader scheduler, see
[Scheduling](scheduling.md); for the configuration entry point, see [Configuration](CONFIGURATION.md).

## Declaring a dependency

Use `dependsOn()` on the `JobBuilder`. It accepts either a variadic list of names or a single array,
and each name must match the `named()` value of another registered job:

```php
use Daycry\Jobs\Cron\Scheduler;

public function init(Scheduler $scheduler): void
{
    $scheduler->define('command', 'app:generate-report')
        ->named('generate-report')
        ->dailyAt('02:00');

    $scheduler->define('command', 'app:send-report')
        ->named('send-report')
        ->dailyAt('02:00')
        ->dependsOn('generate-report');

    // Variadic or array form both work:
    $scheduler->define('command', 'app:archive-report')
        ->named('archive-report')
        ->dailyAt('02:00')
        ->dependsOn('generate-report', 'send-report');
        // equivalently: ->dependsOn(['generate-report', 'send-report'])
}
```

> **Note:** `dependsOn()` references jobs by their **name**. Always give dependent jobs an explicit
> `named()` so the relationship is stable. A job without a name falls back to a synthetic key derived
> from its handler and payload hash, which is awkward to reference.

## How the order is computed

`Scheduler::getExecutionOrder()` builds a dependency graph from every registered definition and
performs a **Kahn topological sort** by in-degree:

1. Index every definition by its resolved name.
2. For each `dependsOn` entry, add an edge `dependency -> dependent` and increment the dependent's
   in-degree.
3. Seed a work queue with all nodes of in-degree 0 (no dependencies).
4. Repeatedly pop a node, append it to the order, and decrement each neighbour's in-degree; when a
   neighbour reaches 0 it is enqueued.

The result is a list where every job appears **after** all of its dependencies. `CronRunner` then
walks that list and, for each due/enabled job, either enqueues it (if it declares a `queue()`) or
runs it inline.

```php
// Resolved order for the example above:
// 1. generate-report   (in-degree 0)
// 2. send-report       (depends on generate-report)
// 3. archive-report    (depends on generate-report + send-report)
```

> **Note:** Dependencies define **order**, not conditional execution. The current runner sorts the
> due jobs topologically and runs each one; it does not skip a dependent because its dependency was
> not due this minute, nor does it abort the chain if a dependency *fails* at runtime. Model hard
> "run B only if A succeeded" gating inside your handlers (e.g. have A enqueue B on success), or keep
> dependent jobs on the same schedule so they run together in order.

## Unknown dependencies

If a job depends on a name that is not registered, `getExecutionOrder()` throws immediately:

```php
$scheduler->define('command', 'app:send-report')
    ->named('send-report')
    ->dependsOn('does-not-exist'); // <-- not registered

// RuntimeException: Dependency 'does-not-exist' for job 'send-report' does not exist.
```

This is a fail-fast guard: a typo in a dependency name surfaces as an exception the first time the
cron runner evaluates the schedule, rather than silently running in the wrong order.

## Cycle detection

A circular dependency cannot be ordered. After the topological sort, the scheduler checks that every
node was emitted; if not, a cycle exists and it throws:

```php
$scheduler->define('command', 'a')->named('a')->dependsOn('b');
$scheduler->define('command', 'b')->named('b')->dependsOn('a');

// RuntimeException: Circular dependency detected in jobs.
```

> **Warning:** Both the unknown-dependency and cycle errors are raised from
> `Scheduler::getExecutionOrder()`, which `CronRunner::run()` calls every minute. A broken dependency
> graph therefore stops the **entire** cron run, not just the offending job. Validate your `init()`
> wiring (for example with a one-off `jobs:cronjob:run -testTime ...`) before deploying.

## Combining with other features

Dependencies compose with the rest of the builder API:

```php
$scheduler->define('command', 'app:nightly-aggregate')
    ->named('nightly-aggregate')
    ->dailyAt('01:00')
    ->singleInstance()          // never overlap with itself (see concurrency.md)
    ->environments('production');

$scheduler->define('command', 'app:publish-dashboard')
    ->named('publish-dashboard')
    ->dailyAt('01:00')
    ->queue('reports')          // enqueued instead of inline
    ->maxRetries(3)
    ->dependsOn('nightly-aggregate');
```

See [Concurrency & Resilience](concurrency.md) for `singleInstance()`, [Retries](RETRIES.md) for
`maxRetries()`, and [Scheduling](scheduling.md) for the frequency helpers.
