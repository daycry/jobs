# Migration to v3

v3.0 is a major, breaking release. It collapses the previously coexisting architectures into a
single clean design. The legacy mutable `Job` builder, the V1 `Scheduler`, the performance loggers,
the email notification service, and the old CLI commands have been **removed**. This guide maps the
old surface to its v3 replacement.

> If you are coming from a `v2.0.0-alpha` build, the opt-in `Daycry\Jobs\V2\` namespace
> (`JobDefinition`, `JobLease`, `QueueBackend`, `LegacyWorkerAdapter`, `TypedJobHandler`) has been
> promoted to the root namespace and is now the only architecture. `LegacyWorkerAdapter` is gone —
> every backend implements `QueueBackend` natively.

## What was removed

| Removed | Replacement |
|---------|-------------|
| Mutable `Job` builder + traits (`IdentityTrait`, `StateTrait`, `FrequenciesTrait`, ...) | `Jobs::define()` → fluent `JobBuilder` → immutable `JobDefinition`. |
| V1 `Scheduler` | `Daycry\Jobs\Cron\Scheduler` registered in `Config\Jobs::init()`. |
| Handlers extending `Job` (`ClosureJob`, `CommandJob`, `ShellJob`, `UrlJob`, `EventJob`) | Handlers implementing `JobHandlerInterface` (`AbstractJobHandler` / `TypedJobHandler`). |
| `QueueInterface` + `WorkerInterface` (`watch()`, `removeJob()`) | Single `QueueBackend` contract with lease semantics. |
| `QueueManager` singleton | `BackendFactory` / `Jobs::backend()`. |
| Performance loggers (`JobLogger`, file/db handlers), `$logPerformance`, `$log`, `$loggers` | Removed. Use the metrics collector (`Config\Jobs::$metricsCollector`) and your handler's own logging. |
| `NotificationService` / email notifications, `notifyOnSuccess()` / `notifyOnFailure()` | Removed. Send notifications from your handler or a downstream job. |
| `JobsLogModel` and the extended logs table | Removed from the runtime. The history table (`$tableName`) and `jobs:queue:purge` remain for the database backend. |
| `JobLifecycleCoordinator` + `RequeueHelper` retry loop | One attempt in `JobRuntime`; the worker requeues with backoff via `QueueBackend::nack(delay)`. |
| Callbacks/chaining (`then()`, `catch()`, `finally()`, `chain()`) | Removed. Compose work explicitly (e.g. dispatch a follow-up job from a handler). |
| `$batchSize` config (never implemented) | Removed. |
| CLI: `jobs:queue:run`, `jobs:redis:reap-stuck`, `jobs:cronjob:{enable,disable,list,history}`, `jobs:health` | `jobs:queue:work`, `jobs:queue:reap`, `jobs:cronjob:run`, `jobs:queue:purge`. |

## API mapping

### Defining and dispatching a job

Old (v1):

```php
use Daycry\Jobs\Job;

$job = (new Job('command', 'app:report'))
    ->named('daily-report')
    ->dailyAt('02:00')
    ->maxRetries(3)
    ->enqueue('reports');
```

v3:

```php
use Daycry\Jobs\Jobs;

Jobs::define('command', 'app:report')
    ->named('daily-report')
    ->dailyAt('02:00')
    ->queue('reports')
    ->maxRetries(3)
    ->dispatch();
```

`dispatch(?string $backend = null)` enqueues onto the named backend (or the configured default) and
returns the backend-assigned id. `toDefinition()` materialises an immutable `JobDefinition` without
dispatching.

### Builder methods

| v1 | v3 |
|----|----|
| `new Job($handler, $payload)` | `Jobs::define($handler, $payload)` |
| `->named($n)` | `->named($n)` |
| `->enqueue($queue)` / `->push()` | `->queue($queue)->dispatch()` |
| `->maxRetries($n)` | `->maxRetries($n)` |
| `->priority($p)` | `->priority($p)` |
| `->timeout($s)` | `->timeout($s)` |
| `->singleInstance()` | `->singleInstance()` |
| `->environments(...)` | `->environments(...)` |
| `->dependsOn(...)` | `->dependsOn(...)` |
| `->everyMinute()` / `->hourly()` / `->daily()` / `->dailyAt('HH:MM')` / `->weekly()` / `->monthly()` / `->quarterly()` / `->yearly()` | identical helpers on `JobBuilder` |
| `->notifyOnSuccess()` / `->notifyOnFailure()` | removed (no built-in notifications) |
| `->then()` / `->catch()` / `->chain()` | removed (compose explicitly) |
| — | `->idempotencyKey($k)` (new, opt-in) |
| — | `->enabled()` / `->disable()` (honoured by the cron runner) |

### Handlers

Old handlers extended the `Job` god-object. v3 handlers implement `JobHandlerInterface` and receive
an immutable `JobContext` — no scheduling or queue state.

Old (v1):

```php
class MyJob extends \Daycry\Jobs\Job
{
    public function handle($payload)
    {
        // ...
    }
}
```

v3:

```php
use Daycry\Jobs\Handlers\AbstractJobHandler;
use Daycry\Jobs\Execution\JobContext;

final class MyHandler extends AbstractJobHandler
{
    public function handle(JobContext $ctx): mixed
    {
        $payload = $ctx->payload; // plus $ctx->name, $ctx->queue, $ctx->attempt, $ctx->meta
        // ... business logic; throw to signal failure ...
        return 'done';
    }
}
```

Register the key in `Config\Jobs::$handlers`:

```php
public array $handlers = [
    'command' => CommandHandler::class,
    'myhandler' => \App\Jobs\MyHandler::class,
];
```

For typed payloads, extend `TypedJobHandler` and declare `payloadType()`; the payload is rehydrated
into your DTO before `run()` is called.

### Scheduling (cron)

Old: jobs were registered on the V1 `Scheduler`. v3 registers them in `Config\Jobs::init()`:

```php
use Daycry\Jobs\Cron\Scheduler;

public function init(Scheduler $scheduler): void
{
    $scheduler->define('command', 'app:report')
        ->named('daily-report')
        ->dailyAt('02:00')
        ->queue('reports')
        ->maxRetries(3);
}
```

Run the scheduler every minute from system cron:

```cron
* * * * * cd /path/to/app && php spark jobs:cronjob:run >> /dev/null 2>&1
```

Definitions with a `queue()` are enqueued; the rest run inline. The runner honours
`enabled()`/`environments()` and resolves `dependsOn()` in topological order.

### Resolving a backend directly

Old: `QueueManager::instance()->get('redis')`. v3:

```php
use Daycry\Jobs\Jobs;

$backend = Jobs::backend('redis'); // or Jobs::backend() for the default worker
$id      = $backend->enqueue($definition);
```

## CLI command mapping

| v1 | v3 |
|----|----|
| `jobs:queue:run <queue>` | `jobs:queue:work <queue> [--once] [--max N] [--backend name]` |
| `jobs:redis:reap-stuck <queue>` | `jobs:queue:reap <queue> [--backend name]` (all backends) |
| `jobs:cronjob:run` | `jobs:cronjob:run` (now drives the v3 cron runner) |
| `jobs:cronjob:enable` / `disable` / `list` / `history` | removed — control scheduling via `enabled()`/`disable()` in `init()` |
| `jobs:health` | removed — use your metrics collector |
| `jobs:queue:purge` | `jobs:queue:purge` (unchanged; database backend maintenance) |

The worker shuts down gracefully on SIGTERM/SIGINT, applies a circuit breaker per backend, and
honours per-queue rate limits.

## Configuration changes

- **Removed:** `$logPerformance`, `$log`, `$loggers`, `$filePath`, `$maxLogsPerJob`,
  `$sensitiveKeys`, `$maxOutputLength`, `$batchSize`, `$workers` (now `$backends`), `$jobs` (now
  `$handlers`), and the email/notification settings.
- **Added:** `$handlers`, `$backends`, `$queueHandlers` (per-queue allowlist), `$allowedEvents`,
  `$allowAllShellCommands`, `$signingKey`, `$verifyEnvelopeSignature`, `$idempotencyTtl`,
  `$databaseVisibilityTimeout`, `$metricsCollector`.
- **Security defaults:** `ShellHandler` is now deny-by-default (`$allowedShellCommands = []` rejects
  everything); `EventHandler` requires `$allowedEvents`; envelope signature verification is on by
  default.

See [Configuration](CONFIGURATION.md) for the complete, current list.

## Suggested migration steps

1. Replace `new Job(...)->enqueue(...)` call sites with `Jobs::define(...)->queue(...)->dispatch()`.
2. Convert each `Job` subclass into a `JobHandlerInterface` implementation and register its key in
   `Config\Jobs::$handlers`.
3. Move scheduled-job registration into `Config\Jobs::init()` using the v3 `Scheduler`.
4. Update `Config\Jobs`: rename `$jobs` → `$handlers`, `$workers` → `$backends`; add the security
   allowlists; remove logger/notification/batch settings.
5. Set `JOBS_SIGNING_KEY` (or `$signingKey`) so envelope signing is active in production.
6. Update crontab/supervisor entries: `jobs:queue:run` → `jobs:queue:work`,
   `jobs:redis:reap-stuck` → `jobs:queue:reap`.
7. Make handlers idempotent; add `idempotencyKey()` where a duplicate run must be prevented.
