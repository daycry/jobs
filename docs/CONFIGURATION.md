# Configuration

All settings live in `Daycry\Jobs\Config\Jobs`. Copy the file into your application
(`app/Config/Jobs.php`, keeping the `Config` namespace) to override defaults — CodeIgniter's
service locator favours the application namespace.

Every property documented below exists in the v3 `Config\Jobs`. Nothing here is invented.

## Handlers

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$handlers` | `array<string, class-string>` | `command`, `shell`, `closure`, `event`, `url` | Single source of truth mapping a handler **key** to a `JobHandlerInterface` class. `HandlerRegistry` resolves keys exclusively from here. |
| `$queueHandlers` | `array<string, list<string>>` | `[]` | Per-queue allowlist of handler keys. A queue listed here may run **only** the keys it declares. A queue absent from the map (or with an empty list) imposes no restriction — set it explicitly in production so remote queues cannot invoke `shell`/`command`. |

```php
public array $handlers = [
    'command' => CommandHandler::class,
    'shell'   => ShellHandler::class,
    'closure' => ClosureHandler::class,
    'event'   => EventHandler::class,
    'url'     => UrlHandler::class,
];

// Lock the 'reports' queue to the command handler, and 'web' to url/event only.
public array $queueHandlers = [
    'reports' => ['command'],
    'web'     => ['url', 'event'],
];
```

## Handler Security

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$allowedShellCommands` | `list<string>` | `[]` | Allowlist of binaries `ShellHandler` may run. **Deny-by-default**: an empty list rejects all execution. Entries are matched against the candidate via `realpath()`, so `/tmp/echo` cannot impersonate `/usr/bin/echo`. |
| `$allowAllShellCommands` | `bool` | `false` | Explicit, insecure escape hatch. When `true`, `ShellHandler` runs any binary even with an empty allowlist. |
| `$allowedEvents` | `list<string>` | `[]` | Allowlist of event names `EventHandler` may trigger. **Empty = deny all** (secure default). |

```php
// ShellHandler is deny-by-default. Allow specific absolute paths:
public array $allowedShellCommands = ['/usr/bin/ls', '/usr/bin/git'];

// EventHandler only fires events you explicitly permit:
public array $allowedEvents = ['user.registered', 'cache.warm'];
```

`ShellHandler` executes through `proc_open()` with an argv array — never `/bin/sh -c` — so shell
metacharacters carry no attack surface. `UrlHandler` is SSRF-hardened (http/https only, private/
reserved IPs rejected, SSL verification forced on, redirects disabled). See [Security](security.md)
for the full handler-security model.

## Envelope Signing

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$signingKey` | `?string` | `null` | HMAC-SHA256 key used to sign queue envelopes. When `null`, the signer falls back to `env('JOBS_SIGNING_KEY')` and then to the CodeIgniter `Encryption` key. If no key resolves, signing/verification operate in insecure pass-through mode (logged as `critical`). |
| `$verifyEnvelopeSignature` | `bool` | `true` | When `true`, the worker rejects messages whose HMAC signature is missing or invalid (when a key is available). Set `false` only for trusted, private backends. |

The signature is computed over the **immutable identity fields** only (`job`, `payload`, `queue`,
`priority`, `maxRetries`, `name`, `identifier`); the mutable `attempts`/`schedule` are excluded so
the signature survives a requeue. See [Security](security.md) for the key-resolution chain,
verification path, and threat model.

## Idempotency

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$idempotencyTtl` | `int` | `86400` | TTL (seconds) for idempotency keys stored by `IdempotencyGuard`. |

Idempotency is opt-in per job via `JobBuilder::idempotencyKey()`. See [Retries](RETRIES.md).

## Rate Limiting

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$queueRateLimits` | `array<string, int>` | `[]` | Maximum jobs processed per minute per queue. `0` or missing = unlimited. The worker skips a cycle (logging `[Rate Limited]`) when the limit is reached. |

```php
public array $queueRateLimits = [
    'high_priority' => 100,
    'default'       => 50,
];
```

See [Concurrency & Resilience](concurrency.md) for the rate limiter, circuit breaker and
single-instance locking in detail.

## Dead Letter Queue

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$deadLetterQueue` | `?string` | `null` | Queue name used by the **opt-in** `DeadLetterQueue::store()` helper (a manual, app-level facility). The worker itself does **not** consult this value — on exhausted retries it calls `backend->abandon()`, which settles natively (Beanstalk **bury**, database row marked `failed`, Redis **drop**) and logs a `critical` entry. To actually route a failed job to this queue you must call `DeadLetterQueue::store()` yourself. |

## Timeouts

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$jobTimeout` | `int` | `300` | Maximum execution time per job in seconds (`0` = unlimited). May be overridden per job. |
| `$defaultTimeout` | `?int` | `null` | Default per-attempt timeout applied to jobs that do not declare their own via `JobBuilder::timeout()`. `null` disables the global timeout. |

The runtime enforces the per-job `timeout` if set, otherwise `$defaultTimeout`. When PHP `pcntl` is
available the timeout **interrupts** the running job (a SIGALRM handler that throws); otherwise it
degrades to a soft post-hoc check that cannot abort a runaway job.

## Worker Behaviour

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$pollInterval` | `int` | `5` | Seconds the worker sleeps after an empty/rate-limited/circuit-open/error cycle before polling again. |
| `$blockingFetch` | `bool` | `false` | **Reserved** — not yet wired into the shipped worker; has no effect. The worker always fetches non-blocking (Beanstalk uses a hardcoded `reserveWithTimeout(5)`; Redis uses non-blocking `rpoplpush`). |
| `$blockingFetchTimeout` | `int` | `5` | **Reserved** — not yet wired into the shipped worker; has no effect. (Beanstalk's reserve timeout is hardcoded to 5s, not driven by this value.) |
| `$circuitBreakerThreshold` | `int` | `5` | Consecutive backend failures before the circuit opens. |
| `$circuitBreakerCooldown` | `int` | `60` | Seconds the circuit stays open before the worker retries the backend. |

## Reaper / Visibility

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$redisProcessingVisibilityTimeout` | `int` | `300` | Visibility timeout (seconds) the Redis backend reaper uses to decide when an in-flight job belongs to a crashed worker and must be returned to the waiting list. |
| `$databaseVisibilityTimeout` | `int` | `300` | Visibility timeout (seconds) the database backend reaper uses to return an `in_progress` row to `pending`. Must exceed the maximum expected job runtime to avoid reclaiming live jobs. |
| `$serviceBusLockTimeout` | `int` | `60` | Peek-lock timeout (seconds) requested when locking Service Bus messages. Must be ≥ the maximum job runtime, otherwise the broker may redeliver mid-execution. |

These are used by `jobs:queue:reap`. Beanstalk and Service Bus recover stalled work natively.

## Retry / Backoff

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$retryBackoffStrategy` | `string` | `none` | One of `none`, `fixed`, `exponential`. |
| `$retryBackoffBase` | `int` | `5` | Base delay (seconds) used to compute the first retry delay. |
| `$retryBackoffMultiplier` | `float` | `2.0` | Multiplier for the exponential strategy: `delay = base * multiplier^(attempt-2)`. |
| `$retryBackoffMax` | `int` | `300` | Upper cap (seconds) on any computed delay. |
| `$retryBackoffJitter` | `bool` | `true` | Add ±15% random jitter to the computed delay (exponential strategy). |

See [Retries & Backoff](RETRIES.md) for the full model.

## Queues & Backends

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$queues` | `list<string>\|string` | `'default,dummy'` | Comma list or array of available queue names. The first entry is the worker's default when no queue is given. |
| `$worker` | `string` | `'sync'` | Default backend key (must exist in `$backends`). Resolved by `BackendFactory`. |
| `$backends` | `array<string, class-string>` | `sync`, `database`, `redis`, `beanstalk`, `serviceBus` | Single source of truth mapping a backend name to a `QueueBackend` class. Used by the worker, the cron runner and `BackendFactory`. |

```php
public array $backends = [
    'sync'       => SyncBackend::class,
    'database'   => DatabaseBackend::class,
    'redis'      => RedisBackend::class,
    'beanstalk'  => BeanstalkBackend::class,
    'serviceBus' => ServiceBusBackend::class,
];

public string $worker = 'database';
```

## Backend-specific Settings

### Database / history table

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$database` | `array{group: ?string, table: string}` | `['group' => null, 'table' => 'queues']` | Connection group and table for the database queue backend. |
| `$databaseGroup` | `?string` | `null` | Connection group used by the Jobs migrations (`null` = default group). |
| `$tableName` | `string` | `'jobs'` | Name of the table created by the Jobs history migration. |

### Redis

Relies on `ext-redis`; host/port are taken from the CodeIgniter Redis configuration / environment.

### Beanstalk

| Property | Type | Default |
|----------|------|---------|
| `$beanstalk['host']` | `string` | `127.0.0.1` |
| `$beanstalk['port']` | `int` | `11300` |

### Azure Service Bus

| Property | Type | Description |
|----------|------|-------------|
| `$serviceBus['url']` | `string` | Full queue endpoint, e.g. `https://<namespace>.servicebus.windows.net/<queue>`. |
| `$serviceBus['issuer']` | `string` | SAS key name. |
| `$serviceBus['secret']` | `string` | SAS key value — prefer `env('SERVICEBUS_SECRET')`. |

## Metrics

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$metricsCollector` | `?string` | `InMemoryMetricsCollector::class` | FQCN of a `MetricsCollectorInterface` implementation. `null` disables metrics (all increment/observe calls no-op). The in-memory default is fine for local/dev but not for production scraping. |

## Scheduled jobs: `init()`

`Config\Jobs::init(Scheduler $scheduler)` is called by `jobs:cronjob:run` before evaluating due
definitions. Register scheduled jobs on the fluent `Scheduler` here:

```php
public function init(Scheduler $scheduler): void
{
    $scheduler->define('command', 'app:report')
        ->named('daily-report')
        ->dailyAt('02:00')
        ->queue('reports')
        ->maxRetries(3);

    $scheduler->define('closure', static fn () => cache()->clean())
        ->named('cache-clean')
        ->everyMinute();
}
```

Definitions that declare a `queue()` are **enqueued**; the rest run **inline**. The runner honours
`enabled()`/`environments()` and executes in topological order of `dependsOn()`.

## Minimal example

```php
$cfg                       = config('Jobs');
$cfg->worker               = 'redis';
$cfg->queues               = 'default,reports';
$cfg->signingKey           = env('JOBS_SIGNING_KEY');
$cfg->retryBackoffStrategy = 'exponential';
$cfg->retryBackoffBase     = 3;
$cfg->retryBackoffMax      = 180;
$cfg->deadLetterQueue      = 'failed_jobs';
```
